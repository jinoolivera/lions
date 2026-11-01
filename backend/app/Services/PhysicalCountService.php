<?php

namespace App\Services;

use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\StockCount;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PhysicalCountService
{
    public function __construct(private readonly InventoryService $inventory) {}

    public function count(array $data, int $userId): StockCount
    {
        $counted = BigDecimal::of((string) $data['counted_quantity'])->toScale(6, RoundingMode::UNNECESSARY);
        $payloadParts = [$data['warehouse_id'], $data['product_id'], (string) $counted, trim($data['reason'])];
        if (isset($data['location_id'])) $payloadParts[] = 'location:'.$data['location_id'];
        $payloadHash = hash('sha256', implode('|', $payloadParts));

        return DB::transaction(function () use ($data, $userId, $counted, $payloadHash): StockCount {
            $existing = StockCount::query()->where('operation_key', $data['operation_key'])->first();
            if ($existing) {
                if (! hash_equals($existing->payload_hash, $payloadHash)) throw new ConflictHttpException('La clave de conteo ya fue usada con otros datos.');
                return $existing;
            }
            $warehouse = Warehouse::query()->where('is_active', true)->with('locations')->findOrFail($data['warehouse_id']);
            $location = isset($data['location_id'])
                ? WarehouseLocation::query()->where('warehouse_id', $warehouse->id)->findOrFail($data['location_id'])
                : ($warehouse->locations->firstWhere('is_default', true) ?? $warehouse->locations->firstOrFail());
            $product = Product::query()->where('is_active', true)->findOrFail($data['product_id']);
            $presentation = $product->presentations()->where('is_base', true)->where('is_active', true)->firstOrFail();
            DB::table('stock_balances')->insertOrIgnore([
                'warehouse_location_id' => $location->id, 'product_id' => $product->id, 'physical_quantity' => '0.000000',
                'reserved_quantity' => '0.000000', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $balance = StockBalance::query()->where('warehouse_location_id', $location->id)->where('product_id', $product->id)->lockForUpdate()->firstOrFail();
            $system = BigDecimal::of($balance->physical_quantity)->toScale(6);
            if ($counted->compareTo(BigDecimal::of($balance->reserved_quantity)) < 0) {
                throw ValidationException::withMessages(['counted_quantity' => 'El conteo no puede quedar por debajo de la cantidad reservada.']);
            }
            $variance = $counted->minus($system)->toScale(6);
            $count = StockCount::query()->create([
                'warehouse_id' => $warehouse->id, 'warehouse_location_id' => $location->id, 'product_id' => $product->id,
                'user_id' => $userId, 'operation_key' => $data['operation_key'], 'payload_hash' => $payloadHash,
                'system_quantity' => (string) $system, 'counted_quantity' => (string) $counted,
                'variance_quantity' => (string) $variance, 'reason' => trim($data['reason']), 'counted_at' => now(),
            ]);
            if (! $variance->isZero()) {
                if (! $product->allows_fractional_quantity && $counted->stripTrailingZeros()->getScale() > 0) {
                    throw ValidationException::withMessages(['counted_quantity' => 'El conteo debe ser compatible con la unidad base del producto.']);
                }
                $movement = $this->inventory->record(
                    $product, $location, $presentation, $variance->isPositive() ? 'adjustment_in' : 'adjustment_out',
                    (string) $variance->abs(), 'Conteo físico '.$count->id.': '.trim($data['reason']),
                    'sc:'.$data['operation_key'], $userId,
                );
                $count->forceFill(['inventory_movement_id' => $movement->id])->save();
            }
            return $count->fresh();
        }, 3);
    }
}
