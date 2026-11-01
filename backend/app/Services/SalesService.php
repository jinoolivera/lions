<?php

namespace App\Services;

use App\Models\CashSession;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductPresentation;
use App\Models\Sale;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class SalesService
{
    public function __construct(private readonly InventoryService $inventory) {}

    public function create(array $data, int $userId, bool $inventoryAlreadyConsumed = false): Sale
    {
        $normalized = $data;
        $normalized['customer_id'] = isset($data['customer_id']) ? (int) $data['customer_id'] : null;
        $normalized['price_list'] = $data['price_list'] ?? 'retail';
        $normalized['warehouse_location_id'] = $data['warehouse_location_id'] ?? null;
        $normalized['lines'] = collect($data['lines'])->map(fn ($line) => [
            'product_id' => (int) $line['product_id'], 'presentation_id' => (int) $line['presentation_id'], 'quantity' => (string) $line['quantity'], 'discount_cents' => (int) ($line['discount_cents'] ?? 0),
        ])->all();
        $normalized['payments'] = collect($data['payments'] ?? [])->map(fn ($payment) => [
            'method' => $payment['method'], 'amount_cents' => (int) $payment['amount_cents'], 'reference' => $payment['reference'] ?? null,
        ])->all();
        $payloadHash = hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($data, $userId, $payloadHash, $inventoryAlreadyConsumed): Sale {
            $existing = Sale::query()->where('operation_key', $data['operation_key'])->first();
            if ($existing) {
                if (! hash_equals($existing->payload_hash, $payloadHash)) {
                    throw new ConflictHttpException('La clave de operación ya fue usada con datos distintos.');
                }
                return $existing->load(['lines', 'payments']);
            }

            $session = CashSession::query()->where('status', 'open')->lockForUpdate()->findOrFail($data['cash_session_id']);
            $seller = User::query()->findOrFail($userId);
            $warehouse = Warehouse::query()->with('locations')->findOrFail($session->warehouse_id);
            $customer = isset($data['customer_id']) ? Customer::query()->where('is_active', true)->lockForUpdate()->findOrFail($data['customer_id']) : null;
            $location = isset($data['warehouse_location_id'])
                ? $warehouse->locations->firstWhere('id', (int) $data['warehouse_location_id'])
                : ($warehouse->locations->firstWhere('is_default', true) ?? $warehouse->locations->first());
            if (! $location) throw ValidationException::withMessages(['warehouse_location_id' => 'Selecciona una ubicación activa del almacén de la caja.']);
            $computed = [];
            $subtotal = 0;
            $total = 0;
            foreach ($data['lines'] as $line) {
                $product = Product::query()->where('is_active', true)->findOrFail($line['product_id']);
                $presentation = ProductPresentation::query()->where('product_id', $product->id)->where('is_active', true)->findOrFail($line['presentation_id']);
                $quantity = BigDecimal::of((string) $line['quantity'])->toScale(6, RoundingMode::UNNECESSARY);
                if (! $quantity->isPositive()) throw ValidationException::withMessages(['lines' => 'La cantidad debe ser mayor que cero.']);
                $baseQuantity = $quantity->multipliedBy($presentation->factor_to_base)->toScale(6, RoundingMode::UNNECESSARY);
                if (! $product->allows_fractional_quantity && $baseQuantity->stripTrailingZeros()->getScale() > 0) {
                    throw ValidationException::withMessages(['lines' => "La cantidad de {$product->name} no coincide con su unidad base."]);
                }
                $unitPrice = ($data['price_list'] ?? 'retail') === 'wholesale' && $presentation->wholesale_price_cents !== null
                    ? (int) $presentation->wholesale_price_cents : (int) $presentation->price_cents;
                $lineTotal = $quantity->multipliedBy((string) $unitPrice)->toScale(0, RoundingMode::HALF_UP)->toInt();
                $discount = (int) ($line['discount_cents'] ?? 0);
                if ($discount > $lineTotal) throw ValidationException::withMessages(['lines' => "El descuento de {$product->name} supera el importe de la línea."]);
                if ($discount > 0 && ! in_array($seller->role, ['admin', 'manager'], true)) {
                    throw ValidationException::withMessages(['lines' => 'Solo gerencia o administración pueden aprobar descuentos.']);
                }
                $subtotal += $lineTotal;
                $total += $lineTotal - $discount;
                $computed[] = compact('product', 'presentation', 'quantity', 'unitPrice', 'lineTotal', 'discount');
            }
            $paid = array_sum(array_column($data['payments'] ?? [], 'amount_cents'));
            if ($paid > $total) throw ValidationException::withMessages(['payments' => 'La suma de pagos excede el total de la venta.']);
            $credit = $total - $paid;
            if ($credit > 0 && $customer === null) throw ValidationException::withMessages(['customer_id' => 'Selecciona un cliente para registrar el saldo a crédito.']);
            if ($credit > 0) {
                $outstanding = (int) $customer->sales()->whereColumn('total_cents', '>', 'paid_cents')->selectRaw('SUM(total_cents - paid_cents) as aggregate')->value('aggregate');
                if ($outstanding + $credit > $customer->credit_limit_cents) {
                    throw ValidationException::withMessages(['customer_id' => 'El saldo de esta venta supera el crédito disponible del cliente.']);
                }
            }

            $sale = Sale::query()->create([
                'cash_session_id' => $session->id, 'warehouse_id' => $warehouse->id, 'warehouse_location_id' => $location->id, 'user_id' => $userId,
                'customer_id' => $customer?->id,
                'sale_number' => 'V-' . now()->format('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(2))),
                'operation_key' => $data['operation_key'], 'payload_hash' => $payloadHash,
                'customer_name' => $customer?->name ?? ($data['customer_name'] ?? null), 'subtotal_cents' => $subtotal,
                'total_cents' => $total, 'paid_cents' => $paid, 'status' => $credit > 0 ? 'open' : 'completed', 'sold_at' => now(),
                'due_date' => $credit > 0 ? ($data['due_date'] ?? null) : null,
            ]);
            foreach ($computed as $index => $row) {
                $line = $sale->lines()->create([
                    'product_id' => $row['product']->id, 'product_presentation_id' => $row['presentation']->id,
                    'product_name_snapshot' => $row['product']->name, 'presentation_name_snapshot' => $row['presentation']->name,
                    'factor_to_base' => $row['presentation']->factor_to_base, 'quantity' => (string) $row['quantity'],
                    'unit_price_cents' => $row['unitPrice'], 'subtotal_cents' => $row['lineTotal'], 'discount_cents' => $row['discount'],
                    'discount_approved_by_user_id' => $row['discount'] > 0 ? $userId : null, 'line_total_cents' => $row['lineTotal'] - $row['discount'],
                ]);
                if (! $inventoryAlreadyConsumed) {
                    $this->inventory->record($row['product'], $location, $row['presentation'], 'sale', (string) $row['quantity'],
                        'Venta ' . $sale->sale_number, 'sale:' . $data['operation_key'] . ':' . $index, $userId, null, $sale->id);
                }
            }
            foreach (($data['payments'] ?? []) as $index => $payment) {
                $sale->payments()->create($payment + [
                    'cash_session_id' => $session->id, 'user_id' => $userId, 'kind' => 'sale',
                    'operation_key' => 'sp-' . substr(hash('sha256', $data['operation_key'] . ':' . $index), 0, 60),
                    'payload_hash' => hash('sha256', json_encode($payment, JSON_THROW_ON_ERROR)),
                ]);
            }
            $cashPaid = collect($data['payments'] ?? [])->where('method', 'cash')->sum('amount_cents');
            $session->increment('expected_cents', $cashPaid);
            return $sale->load(['lines', 'payments']);
        }, 3);
    }
}
