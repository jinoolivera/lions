<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductPresentation;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\PurchaseReceipt;
use App\Models\Warehouse;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PurchaseReceptionService
{
    public function __construct(private readonly InventoryService $inventory) {}

    public function receive(PurchaseOrder $order, array $data, ?int $userId): PurchaseReceipt
    {
        $lines = collect($data['lines'])->sortBy('purchase_order_line_id')->values();
        $payloadHash = hash('sha256', json_encode([
            'order' => $order->getKey(), 'warehouse' => $data['warehouse_id'], 'warehouse_location_id' => $data['warehouse_location_id'] ?? null,
            'document' => $data['supplier_document_reference'] ?? null, 'notes' => $data['notes'] ?? null,
            'lines' => $lines->all(),
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($order, $data, $lines, $payloadHash, $userId): PurchaseReceipt {
            $lockedOrder = PurchaseOrder::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $existing = PurchaseReceipt::query()->where('receipt_key', $data['receipt_key'])->first();
            if ($existing) {
                if (! hash_equals($existing->payload_hash, $payloadHash)) {
                    throw new ConflictHttpException('La clave de recepción ya se usó con datos distintos.');
                }

                return $existing->load('lines');
            }
            if (in_array($lockedOrder->status, ['received', 'cancelled'], true)) {
                throw new ConflictHttpException('Esta orden no admite nuevas recepciones.');
            }

            $warehouse = Warehouse::query()->where('is_active', true)->with('locations')->findOrFail($data['warehouse_id']);
            $location = isset($data['warehouse_location_id'])
                ? $warehouse->locations->firstWhere('id', (int) $data['warehouse_location_id'])
                : ($warehouse->locations->firstWhere('is_default', true) ?? $warehouse->locations->first());
            if (! $location) {
                throw ValidationException::withMessages(['warehouse_id' => 'El almacén no tiene una ubicación activa.']);
            }

            $lockedLines = PurchaseOrderLine::query()->where('purchase_order_id', $lockedOrder->getKey())
                ->lockForUpdate()->get()->keyBy('id');
            $requested = [];
            $receiptMerchandiseValue = BigDecimal::zero();
            $remainingMerchandiseValue = BigDecimal::zero();
            foreach ($lines as $receiptLine) {
                $line = $lockedLines->get($receiptLine['purchase_order_line_id']);
                if (! $line) throw ValidationException::withMessages(['lines' => 'Una línea no pertenece a esta orden.']);
                $quantity = BigDecimal::of($receiptLine['quantity'])->toScale(6, RoundingMode::UNNECESSARY);
                $remaining = BigDecimal::of($line->ordered_quantity)->minus(BigDecimal::of($line->received_quantity));
                if (! $quantity->isPositive() || $quantity->compareTo($remaining) > 0) {
                    throw ValidationException::withMessages(['lines' => "La cantidad excede el saldo pendiente de {$line->product_name_snapshot}."]);
                }
                $requested[$line->getKey()] = $quantity;
                $receiptMerchandiseValue = $receiptMerchandiseValue->plus($quantity->multipliedBy($line->unit_cost_cents));
            }
            foreach ($lockedLines as $line) {
                $remaining = BigDecimal::of($line->ordered_quantity)->minus(BigDecimal::of($line->received_quantity));
                $remainingMerchandiseValue = $remainingMerchandiseValue->plus($remaining->multipliedBy($line->unit_cost_cents));
            }
            $willComplete = $lockedLines->every(fn (PurchaseOrderLine $line): bool =>
                BigDecimal::of($line->received_quantity)->plus($requested[$line->getKey()] ?? BigDecimal::zero())
                    ->compareTo(BigDecimal::of($line->ordered_quantity)) >= 0
            );
            $remainingLandedCost = (int) $lockedOrder->landed_cost_cents - (int) $lockedOrder->landed_cost_allocated_cents;
            $allocationTotal = 0;
            if ($remainingLandedCost > 0) {
                $allocationTotal = $willComplete ? $remainingLandedCost : ($remainingMerchandiseValue->isPositive()
                    ? (int) BigDecimal::of($remainingLandedCost)->multipliedBy($receiptMerchandiseValue)
                        ->dividedBy($remainingMerchandiseValue, 0, RoundingMode::HALF_UP)->toInt()
                    : 0);
                $allocationTotal = min($remainingLandedCost, max(0, $allocationTotal));
            }
            $allocationByLine = [];
            $allocated = 0;
            $weightedLines = $lines->values();
            foreach ($weightedLines as $index => $receiptLine) {
                $line = $lockedLines->get($receiptLine['purchase_order_line_id']);
                $quantity = $requested[$line->getKey()];
                $weight = $quantity->multipliedBy($line->unit_cost_cents);
                if ($receiptMerchandiseValue->isZero()) {
                    $presentation = ProductPresentation::query()->findOrFail($line->product_presentation_id);
                    $weight = $quantity->multipliedBy(BigDecimal::of($line->factor_to_base));
                }
                $lineAllocation = $index === $weightedLines->count() - 1 ? $allocationTotal - $allocated
                    : ($allocationTotal > 0 && $receiptMerchandiseValue->isPositive()
                        ? (int) BigDecimal::of($allocationTotal)->multipliedBy($weight)
                            ->dividedBy($receiptMerchandiseValue, 0, RoundingMode::HALF_UP)->toInt()
                        : 0);
                $lineAllocation = max(0, min($allocationTotal - $allocated, $lineAllocation));
                $allocationByLine[$line->getKey()] = $lineAllocation;
                $allocated += $lineAllocation;
            }

            $receipt = PurchaseReceipt::query()->create([
                'purchase_order_id' => $lockedOrder->getKey(), 'warehouse_id' => $warehouse->getKey(), 'warehouse_location_id' => $location->id,
                'user_id' => $userId, 'receipt_key' => $data['receipt_key'], 'payload_hash' => $payloadHash,
                'supplier_document_reference' => $data['supplier_document_reference'] ?? null,
                'notes' => $data['notes'] ?? null, 'received_at' => now(),
            ]);

            foreach ($lines as $receiptLine) {
                $line = $lockedLines->get($receiptLine['purchase_order_line_id']);
                $quantity = $requested[$line->getKey()];

                $product = Product::query()->whereKey($line->product_id)->firstOrFail();
                $presentation = ProductPresentation::query()->whereKey($line->product_presentation_id)
                    ->where('product_id', $line->product_id)->firstOrFail();
                if (BigDecimal::of($line->factor_to_base)->compareTo(BigDecimal::of($presentation->factor_to_base)) !== 0) {
                    throw new ConflictHttpException('La equivalencia cambió después de crear la orden; recepción bloqueada.');
                }

                $allocatedLineCost = (int) ($allocationByLine[$line->getKey()] ?? 0);
                $landedCostPerPresentation = $quantity->isPositive()
                    ? BigDecimal::of($allocatedLineCost)->dividedBy($quantity, 6, RoundingMode::HALF_UP)
                    : BigDecimal::zero();
                $effectivePresentationCost = BigDecimal::of($line->unit_cost_cents)->plus($landedCostPerPresentation);
                $this->inventory->record(
                    $product, $location, $presentation, 'receipt', (string) $quantity,
                    "Recepción de orden {$lockedOrder->order_number}",
                    'pr:'.$data['receipt_key'].':'.$line->getKey(), $userId,
                    (string) $effectivePresentationCost,
                );
                $line->forceFill(['received_quantity' => (string) BigDecimal::of($line->received_quantity)->plus($quantity)->toScale(6)])->save();
                $receipt->lines()->create([
                    'purchase_order_line_id' => $line->getKey(), 'quantity' => (string) $quantity,
                    'unit_cost_cents' => $line->unit_cost_cents, 'landed_cost_cents' => $allocatedLineCost,
                ]);
            }
            $lockedOrder->increment('landed_cost_allocated_cents', $allocationTotal);

            $allLines = PurchaseOrderLine::query()->where('purchase_order_id', $lockedOrder->getKey())->get();
            $complete = $allLines->every(fn (PurchaseOrderLine $line): bool =>
                BigDecimal::of($line->received_quantity)->compareTo(BigDecimal::of($line->ordered_quantity)) >= 0
            );
            $anyReceived = $allLines->contains(fn (PurchaseOrderLine $line): bool =>
                BigDecimal::of($line->received_quantity)->isPositive()
            );
            $lockedOrder->forceFill(['status' => $complete ? 'received' : ($anyReceived ? 'partially_received' : 'ordered')])->save();

            return $receipt->load('lines');
        }, 3);
    }
}
