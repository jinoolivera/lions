<?php

namespace App\Services;

use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductPresentation;
use App\Models\StockBalance;
use App\Models\WarehouseLocation;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class InventoryService
{
    public function consumeReserved(
        Product $product,
        WarehouseLocation $location,
        ProductPresentation $presentation,
        string $quantity,
        string $reason,
        string $operationKey,
        int $userId,
    ): InventoryMovement {
        $quantityDecimal = $this->positiveDecimal($quantity, 'quantity');
        $baseQuantity = $quantityDecimal->multipliedBy(BigDecimal::of($presentation->factor_to_base))->toScale(6, RoundingMode::UNNECESSARY);
        if (! $product->allows_fractional_quantity && $baseQuantity->stripTrailingZeros()->getScale() > 0) {
            throw ValidationException::withMessages(['quantity' => 'La conversión produce una fracción de la unidad base.']);
        }
        $payloadHash = hash('sha256', implode('|', [$product->id, $location->id, $presentation->id, 'sale', (string) $quantityDecimal, $reason]));

        return DB::transaction(function () use ($product, $location, $presentation, $baseQuantity, $reason, $operationKey, $payloadHash, $userId): InventoryMovement {
            $existing = InventoryMovement::query()->where('operation_key', $operationKey)->first();
            if ($existing) {
                if (! hash_equals($existing->payload_hash, $payloadHash)) {
                    throw new ConflictHttpException('La clave de movimiento ya fue usada con datos distintos.');
                }

                return $existing;
            }
            $balance = StockBalance::query()->where('warehouse_location_id', $location->id)->where('product_id', $product->id)->lockForUpdate()->firstOrFail();
            $physical = BigDecimal::of($balance->physical_quantity)->toScale(6);
            $reserved = BigDecimal::of($balance->reserved_quantity)->toScale(6);
            if ($reserved->compareTo($baseQuantity) < 0 || $physical->compareTo($baseQuantity) < 0) {
                throw ValidationException::withMessages(['quantity' => 'La cantidad excede la reserva disponible para entrega.']);
            }
            $nextPhysical = $physical->minus($baseQuantity)->toScale(6, RoundingMode::UNNECESSARY);
            $nextReserved = $reserved->minus($baseQuantity)->toScale(6, RoundingMode::UNNECESSARY);
            $unitCost = $balance->average_unit_cost_cents === null ? null : BigDecimal::of($balance->average_unit_cost_cents)->toScale(6);
            $extendedCost = $unitCost === null ? null : $baseQuantity->multipliedBy($unitCost)->toScale(0, RoundingMode::HALF_UP)->toInt();
            $movement = InventoryMovement::query()->create([
                'warehouse_location_id' => $location->id, 'product_id' => $product->id,
                'product_presentation_id' => $presentation->id, 'user_id' => $userId, 'type' => 'sale',
                'quantity_delta' => (string) $baseQuantity->negated(), 'reason' => $reason,
                'unit_cost_cents' => $unitCost === null ? null : (string) $unitCost, 'extended_cost_cents' => $extendedCost,
                'operation_key' => $operationKey, 'payload_hash' => $payloadHash, 'occurred_at' => now(),
            ]);
            $balance->forceFill(['physical_quantity' => (string) $nextPhysical, 'reserved_quantity' => (string) $nextReserved])->save();

            return $movement;
        }, 3);
    }

    public function record(
        Product $product,
        WarehouseLocation $location,
        ProductPresentation $presentation,
        string $type,
        string $quantity,
        string $reason,
        string $operationKey,
        ?int $userId,
        ?string $unitCostPerPresentationCents = null,
        ?int $saleId = null,
    ): InventoryMovement {
        $quantityDecimal = $this->positiveDecimal($quantity, 'quantity');
        $factor = BigDecimal::of($presentation->factor_to_base)->toScale(6, RoundingMode::UNNECESSARY);
        $baseQuantity = $quantityDecimal->multipliedBy($factor)->toScale(6, RoundingMode::UNNECESSARY);
        $unitCostBaseCents = $unitCostPerPresentationCents === null
            ? null
            : BigDecimal::of($unitCostPerPresentationCents)->dividedBy($factor, 6, RoundingMode::HALF_UP);

        if (! $product->allows_fractional_quantity && $baseQuantity->stripTrailingZeros()->getScale() > 0) {
            throw ValidationException::withMessages(['quantity' => 'La conversión produce una fracción de la unidad base.']);
        }

        $inbound = in_array($type, ['opening', 'receipt', 'adjustment_in', 'transfer_in', 'sale_return'], true);
        $allowedTypes = ['opening', 'receipt', 'adjustment_in', 'adjustment_out', 'damaged', 'transfer_out', 'transfer_in', 'sale', 'sale_return'];
        if (! in_array($type, $allowedTypes, true)) {
            throw ValidationException::withMessages(['type' => 'Tipo de movimiento no permitido.']);
        }

        $delta = $inbound ? $baseQuantity : $baseQuantity->negated();
        $payload = implode('|', [
            $product->getKey(), $location->getKey(), $presentation->getKey(), $type,
            (string) $quantityDecimal, $reason, (string) $unitCostPerPresentationCents,
            ...($saleId === null ? [] : [(string) $saleId]),
        ]);
        $payloadHash = hash('sha256', $payload);

        return DB::transaction(function () use (
            $product, $location, $presentation, $type, $baseQuantity, $inbound,
            $delta, $reason, $operationKey, $payloadHash, $userId, $unitCostBaseCents
        ): InventoryMovement {
            $existing = InventoryMovement::query()->where('operation_key', $operationKey)->first();
            if ($existing) {
                if (! hash_equals($existing->payload_hash, $payloadHash)) {
                    throw new ConflictHttpException('La clave de operación ya fue usada con datos distintos.');
                }

                return $existing;
            }

            if ($type === 'opening' && InventoryMovement::query()
                ->where('product_id', $product->getKey())
                ->where('warehouse_location_id', $location->getKey())
                ->exists()) {
                throw new ConflictHttpException('El saldo inicial de este producto ya fue registrado. Use un ajuste con motivo.');
            }

            DB::table('stock_balances')->insertOrIgnore([
                'warehouse_location_id' => $location->getKey(),
                'product_id' => $product->getKey(),
                'physical_quantity' => '0.000000',
                'reserved_quantity' => '0.000000',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $balance = StockBalance::query()
                ->where('warehouse_location_id', $location->getKey())
                ->where('product_id', $product->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $physical = BigDecimal::of($balance->physical_quantity)->toScale(6);
            $reserved = BigDecimal::of($balance->reserved_quantity)->toScale(6);
            $next = $physical->plus($delta)->toScale(6, RoundingMode::UNNECESSARY);
            if ($next->isNegative() || $next->compareTo($reserved) < 0) {
                throw ValidationException::withMessages(['quantity' => 'El movimiento dejaría el stock disponible en negativo.']);
            }

            if ($type === 'opening' && $physical->isPositive()) {
                throw new ConflictHttpException('El producto ya tiene stock; registre un ajuste con motivo.');
            }

            $averageCost = $balance->average_unit_cost_cents === null
                ? null
                : BigDecimal::of($balance->average_unit_cost_cents)->toScale(6);
            if ($inbound && $unitCostBaseCents !== null && $next->isPositive()) {
                if ($averageCost === null) {
                    // Opening stock without a recorded cost is valued at the first known receipt cost.
                    $averageCost = $unitCostBaseCents;
                } else {
                    $oldValue = $physical->multipliedBy($averageCost);
                    $newValue = $baseQuantity->multipliedBy($unitCostBaseCents);
                    $averageCost = $oldValue->plus($newValue)->dividedBy($next, 6, RoundingMode::HALF_UP);
                }
            }
            $effectiveUnitCostBaseCents = $type === 'sale' && $unitCostBaseCents === null ? $averageCost : $unitCostBaseCents;
            $extendedCostCents = $effectiveUnitCostBaseCents === null
                ? null
                : $baseQuantity->multipliedBy($effectiveUnitCostBaseCents)->toScale(0, RoundingMode::HALF_UP)->toInt();

            $movement = InventoryMovement::query()->create([
                'warehouse_location_id' => $location->getKey(),
                'product_id' => $product->getKey(),
                'product_presentation_id' => $presentation->getKey(),
                'user_id' => $userId,
                'type' => $type,
                'quantity_delta' => (string) $delta,
                'unit_cost_cents' => $effectiveUnitCostBaseCents === null ? null : (string) $effectiveUnitCostBaseCents,
                'extended_cost_cents' => $extendedCostCents,
                'reason' => $reason,
                'operation_key' => $operationKey,
                'payload_hash' => $payloadHash,
                'occurred_at' => now(),
            ]);

            $balance->forceFill([
                'physical_quantity' => (string) $next,
                'average_unit_cost_cents' => $averageCost === null ? null : (string) $averageCost,
            ])->save();

            return $movement;
        }, 3);
    }

    private function positiveDecimal(string $value, string $field): BigDecimal
    {
        try {
            $decimal = BigDecimal::of($value)->toScale(6, RoundingMode::UNNECESSARY);
        } catch (\Throwable) {
            throw ValidationException::withMessages([$field => 'La cantidad debe tener como máximo seis decimales.']);
        }

        if (! $decimal->isPositive()) {
            throw ValidationException::withMessages([$field => 'La cantidad debe ser mayor que cero.']);
        }

        return $decimal;
    }
}
