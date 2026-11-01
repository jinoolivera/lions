<?php

namespace App\Services;

use App\Models\CashSession;
use App\Models\Product;
use App\Models\ProductPresentation;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\SaleReturn;
use App\Models\StockBalance;
use App\Models\User;
use App\Models\WarehouseLocation;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class SaleReturnService
{
    public function __construct(private readonly InventoryService $inventory) {}

    public function create(Sale $sale, array $data, int $userId, bool $cancel = false): SaleReturn
    {
        $user = User::query()->findOrFail($userId);
        if (! in_array($user->role, ['admin', 'manager'], true)) {
            throw ValidationException::withMessages(['role' => 'Solo gerencia o administración pueden autorizar devoluciones y anulaciones.']);
        }
        $lines = $cancel
            ? $sale->lines()->get()->map(fn (SaleLine $line) => ['sale_line_id' => $line->id, 'quantity' => (string) BigDecimal::of($line->quantity)->minus($line->returned_quantity ?? 0)])->filter(fn (array $line) => BigDecimal::of($line['quantity'])->isPositive())->values()->all()
            : $data['lines'];
        $payloadHash = hash('sha256', json_encode([
            'sale_id' => $sale->id, 'lines' => $cancel ? 'all-remaining-lines' : $lines, 'reason' => $data['reason'],
            'refund_method' => $data['refund_method'] ?? null, 'cash_session_id' => $data['cash_session_id'] ?? null, 'cancel' => $cancel,
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($sale, $data, $userId, $lines, $payloadHash, $cancel): SaleReturn {
            $existing = SaleReturn::query()->where('operation_key', $data['operation_key'])->first();
            if ($existing) {
                if (! hash_equals($existing->payload_hash, $payloadHash)) throw new ConflictHttpException('La clave de devolución ya se usó con datos distintos.');
                return $existing->load('lines.saleLine');
            }
            $lockedSale = Sale::query()->with('lines')->lockForUpdate()->findOrFail($sale->id);
            if (in_array($lockedSale->status, ['cancelled', 'returned'], true)) throw new ConflictHttpException('La venta ya fue anulada o devuelta por completo.');
            $location = WarehouseLocation::query()->where('warehouse_id', $lockedSale->warehouse_id)->find($lockedSale->warehouse_location_id);
            if (! $location) throw ValidationException::withMessages(['sale' => 'No se encuentra la ubicación asociada a la venta para reingresar stock.']);
            $returnable = [];
            $amount = 0;
            foreach ($lines as $entry) {
                $line = SaleLine::query()->where('sale_id', $lockedSale->id)->lockForUpdate()->findOrFail($entry['sale_line_id']);
                $quantity = BigDecimal::of((string) $entry['quantity'])->toScale(6, RoundingMode::UNNECESSARY);
                $remainingQuantity = BigDecimal::of($line->quantity)->minus($line->returned_quantity ?? 0)->toScale(6);
                if (! $quantity->isPositive() || $quantity->compareTo($remainingQuantity) > 0) {
                    throw ValidationException::withMessages(['lines' => "La cantidad supera lo pendiente de {$line->product_name_snapshot} para devolver."]);
                }
                $alreadyReturnedCents = (int) DB::table('sale_return_lines')->where('sale_line_id', $line->id)->sum('amount_cents');
                $remainingCents = (int) $line->line_total_cents - $alreadyReturnedCents;
                $lineAmount = $quantity->compareTo($remainingQuantity) === 0 ? $remainingCents
                    : BigDecimal::of($remainingCents)->multipliedBy($quantity)->dividedBy($remainingQuantity, 0, RoundingMode::HALF_UP)->toInt();
                $returnable[] = compact('line', 'quantity', 'lineAmount');
                $amount += $lineAmount;
            }
            if ($amount < 1) throw ValidationException::withMessages(['lines' => 'El importe devuelto debe ser mayor que cero.']);
            $balance = max(0, (int) $lockedSale->total_cents - (int) $lockedSale->paid_cents);
            $refund = max(0, $amount - $balance);
            $cashSession = null;
            if ($refund > 0) {
                if (empty($data['refund_method'])) throw ValidationException::withMessages(['refund_method' => 'Indica cómo se devolverá el importe ya cobrado.']);
                if ($refund > (int) $lockedSale->paid_cents) throw ValidationException::withMessages(['lines' => 'La devolución supera el monto efectivamente cobrado.']);
                if ($data['refund_method'] === 'cash') {
                    $cashSession = CashSession::query()->where('status', 'open')->where('warehouse_id', $lockedSale->warehouse_id)->lockForUpdate()->find($data['cash_session_id'] ?? null);
                    if (! $cashSession) throw ValidationException::withMessages(['cash_session_id' => 'Abre caja en el almacén de la venta para devolver efectivo.']);
                    if ((int) $cashSession->expected_cents < $refund) throw ValidationException::withMessages(['refund_method' => 'El efectivo esperado en caja no cubre la devolución.']);
                }
            }
            $return = SaleReturn::query()->create([
                'sale_id' => $lockedSale->id, 'cash_session_id' => $cashSession?->id, 'user_id' => $userId,
                'return_number' => 'DV-' . now()->format('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(2))),
                'operation_key' => $data['operation_key'], 'payload_hash' => $payloadHash, 'reason' => trim($data['reason']),
                'refund_method' => $refund > 0 ? $data['refund_method'] : null, 'amount_cents' => $amount,
                'refund_cents' => $refund, 'returned_at' => now(),
            ]);
            foreach ($returnable as $index => $row) {
                /** @var SaleLine $line */
                $line = $row['line'];
                $product = Product::query()->findOrFail($line->product_id);
                $presentation = ProductPresentation::query()->findOrFail($line->product_presentation_id);
                $balance = StockBalance::query()->where('warehouse_location_id', $location->id)->where('product_id', $product->id)->first();
                $returnCost = $balance?->average_unit_cost_cents === null ? null
                    : BigDecimal::of($balance->average_unit_cost_cents)->multipliedBy($presentation->factor_to_base)->toScale(6, RoundingMode::HALF_UP)->__toString();
                $return->lines()->create(['sale_line_id' => $line->id, 'quantity' => (string) $row['quantity'], 'amount_cents' => $row['lineAmount']]);
                $line->forceFill(['returned_quantity' => (string) BigDecimal::of($line->returned_quantity ?? 0)->plus($row['quantity'])->toScale(6)])->save();
                $this->inventory->record($product, $location, $presentation, 'sale_return', (string) $row['quantity'],
                    "Devolución {$return->return_number} · venta {$lockedSale->sale_number}",
                    'sale-return:' . $data['operation_key'] . ':' . $index, $userId, $returnCost, $lockedSale->id);
            }
            $newTotal = max(0, (int) $lockedSale->total_cents - $amount);
            $newPaid = (int) $lockedSale->paid_cents - $refund;
            // Re-evaluate including the just-committed quantities still held in this transaction.
            foreach ($returnable as $row) {
                $current = $lockedSale->lines->firstWhere('id', $row['line']->id);
                if ($current) $current->returned_quantity = (string) BigDecimal::of($current->returned_quantity ?? 0)->plus($row['quantity'])->toScale(6);
            }
            $allReturned = $lockedSale->lines->every(fn (SaleLine $line) => BigDecimal::of($line->returned_quantity)->compareTo(BigDecimal::of($line->quantity)) >= 0);
            $lockedSale->forceFill([
                'total_cents' => $newTotal, 'paid_cents' => $newPaid, 'returned_cents' => (int) $lockedSale->returned_cents + $amount,
                'status' => $cancel && $allReturned ? 'cancelled' : ($allReturned ? 'returned' : ($newTotal > $newPaid ? 'open' : 'completed')),
            ])->save();
            if ($cashSession && $refund > 0) $cashSession->decrement('expected_cents', $refund);
            $return->forceFill(['refund_cents' => $refund])->save();
            return $return->load('lines.saleLine');
        }, 3);
    }
}
