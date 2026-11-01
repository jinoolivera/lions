<?php

namespace App\Services;

use App\Models\CashSession;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\DeliveryOrderLine;
use App\Models\DeliveryPick;
use App\Models\DeliveryShipment;
use App\Models\Product;
use App\Models\ProductPresentation;
use App\Models\StockBalance;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class DeliveryOrderService
{
    public function __construct(private readonly InventoryService $inventory, private readonly SalesService $sales) {}

    public function create(array $data, int $userId): DeliveryOrder
    {
        $payloadHash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
        return DB::transaction(function () use ($data, $userId, $payloadHash): DeliveryOrder {
            $existing = DeliveryOrder::query()->where('operation_key', $data['operation_key'])->first();
            if ($existing) {
                if (! hash_equals($existing->payload_hash, $payloadHash)) throw new ConflictHttpException('La clave del pedido ya fue usada con datos distintos.');
                return $existing->load(['customer', 'warehouse', 'lines', 'shipments']);
            }
            $warehouse = Warehouse::query()->where('is_active', true)->with('locations')->findOrFail($data['warehouse_id']);
            $location = isset($data['warehouse_location_id'])
                ? $warehouse->locations->firstWhere('id', (int) $data['warehouse_location_id'])
                : ($warehouse->locations->firstWhere('is_default', true) ?? $warehouse->locations->first());
            if (! $location) throw ValidationException::withMessages(['warehouse_location_id' => 'Selecciona una ubicación activa del almacén.']);
            $customer = Customer::query()->where('is_active', true)->findOrFail($data['customer_id']);
            $order = DeliveryOrder::query()->create([
                'warehouse_id' => $warehouse->id, 'warehouse_location_id' => $location->id, 'customer_id' => $customer->id, 'user_id' => $userId,
                'order_number' => 'P-' . now()->format('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(2))),
                'operation_key' => $data['operation_key'], 'payload_hash' => $payloadHash,
                'status' => 'confirmed', 'source' => $data['source'], 'notes' => $data['notes'] ?? null,
                'delivery_address' => $data['delivery_address'] ?? null, 'delivery_contact' => $data['delivery_contact'] ?? null,
                'dispatch_responsible' => $data['dispatch_responsible'] ?? null, 'ordered_at' => now(),
            ]);
            foreach ($data['lines'] as $input) {
                $product = Product::query()->where('is_active', true)->findOrFail($input['product_id']);
                $presentation = ProductPresentation::query()->where('product_id', $product->id)->where('is_active', true)->findOrFail($input['presentation_id']);
                $quantity = BigDecimal::of((string) $input['quantity'])->toScale(6, RoundingMode::UNNECESSARY);
                if (! $quantity->isPositive()) throw ValidationException::withMessages(['lines' => 'La cantidad debe ser mayor que cero.']);
                $baseQuantity = $quantity->multipliedBy($presentation->factor_to_base)->toScale(6, RoundingMode::UNNECESSARY);
                if (! $product->allows_fractional_quantity && $baseQuantity->stripTrailingZeros()->getScale() > 0) {
                    throw ValidationException::withMessages(['lines' => "La cantidad de {$product->name} no coincide con su unidad base."]);
                }
                DB::table('stock_balances')->insertOrIgnore([
                    'warehouse_location_id' => $location->id, 'product_id' => $product->id,
                    'physical_quantity' => '0.000000', 'reserved_quantity' => '0.000000', 'created_at' => now(), 'updated_at' => now(),
                ]);
                $balance = StockBalance::query()->where('warehouse_location_id', $location->id)->where('product_id', $product->id)->lockForUpdate()->firstOrFail();
                $physical = BigDecimal::of($balance->physical_quantity)->toScale(6);
                $reserved = BigDecimal::of($balance->reserved_quantity)->toScale(6);
                if ($physical->minus($reserved)->compareTo($baseQuantity) < 0) {
                    throw ValidationException::withMessages(['lines' => "Stock disponible insuficiente para {$product->name}."]);
                }
                $balance->forceFill(['reserved_quantity' => (string) $reserved->plus($baseQuantity)->toScale(6)])->save();
                $order->lines()->create([
                    'product_id' => $product->id, 'product_presentation_id' => $presentation->id,
                    'product_name_snapshot' => $product->name, 'presentation_name_snapshot' => $presentation->name,
                    'factor_to_base' => $presentation->factor_to_base, 'ordered_quantity' => (string) $quantity, 'picked_quantity' => '0.000000',
                    'delivered_quantity' => '0.000000', 'unit_price_cents' => $presentation->price_cents,
                ]);
            }
            return $order->load(['customer', 'warehouse', 'lines']);
        }, 3);
    }

    public function pick(DeliveryOrder $order, array $data, int $userId): DeliveryOrder
    {
        $payloadHash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
        return DB::transaction(function () use ($order, $data, $userId, $payloadHash): DeliveryOrder {
            $existing = DeliveryPick::query()->where('pick_key', $data['operation_key'])->first();
            if ($existing) {
                if (! hash_equals($existing->payload_hash, $payloadHash)) throw new ConflictHttpException('La clave de preparación ya fue usada con datos distintos.');
                return DeliveryOrder::query()->with('lines')->findOrFail($order->id);
            }
            $order = DeliveryOrder::query()->with('lines')->lockForUpdate()->findOrFail($order->id);
            if (! in_array($order->status, ['confirmed', 'partially_picked', 'partially_delivered'], true)) throw new ConflictHttpException('El pedido no admite preparación.');
            $pick = DeliveryPick::query()->create(['delivery_order_id' => $order->id, 'user_id' => $userId, 'pick_key' => $data['operation_key'], 'payload_hash' => $payloadHash, 'notes' => $data['notes'] ?? null, 'picked_at' => now()]);
            foreach ($data['lines'] as $input) {
                $line = DeliveryOrderLine::query()->where('delivery_order_id', $order->id)->lockForUpdate()->findOrFail($input['delivery_order_line_id']);
                $quantity = BigDecimal::of((string) $input['quantity'])->toScale(6, RoundingMode::UNNECESSARY);
                $remaining = BigDecimal::of($line->ordered_quantity)->minus($line->picked_quantity)->toScale(6);
                if (! $quantity->isPositive() || $quantity->compareTo($remaining) > 0) throw ValidationException::withMessages(['lines' => 'La cantidad supera lo pendiente por preparar.']);
                $line->forceFill(['picked_quantity' => (string) BigDecimal::of($line->picked_quantity)->plus($quantity)->toScale(6)])->save();
                $pick->lines()->create(['delivery_order_line_id' => $line->id, 'quantity' => (string) $quantity]);
            }
            $order->refresh()->load('lines');
            $allPicked = $order->lines->every(fn (DeliveryOrderLine $line) => BigDecimal::of($line->picked_quantity)->compareTo($line->ordered_quantity) >= 0);
            $order->forceFill(['status' => $allPicked ? 'picked' : 'partially_picked'])->save();
            return $order;
        }, 3);
    }

    public function deliver(DeliveryOrder $order, array $data, int $userId): DeliveryShipment
    {
        $payloadHash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
        return DB::transaction(function () use ($order, $data, $userId, $payloadHash): DeliveryShipment {
            $existing = DeliveryShipment::query()->where('shipment_key', $data['shipment_key'])->first();
            if ($existing) {
                if (! hash_equals($existing->payload_hash, $payloadHash)) throw new ConflictHttpException('La clave de entrega ya fue usada con datos distintos.');
                return $existing->load(['lines', 'sale', 'order', 'guide']);
            }
            $order = DeliveryOrder::query()->with(['customer', 'lines'])->lockForUpdate()->findOrFail($order->id);
            if (! in_array($order->status, ['picked', 'partially_picked', 'partially_delivered'], true)) throw new ConflictHttpException('Primero prepara el pedido antes de entregarlo.');
            $session = CashSession::query()->where('warehouse_id', $order->warehouse_id)->where('status', 'open')->lockForUpdate()->first();
            if (! $session) throw ValidationException::withMessages(['cash_session' => 'Abre caja en el almacén del pedido antes de confirmar la entrega.']);
            $warehouse = Warehouse::query()->with('locations')->findOrFail($order->warehouse_id);
            $location = $warehouse->locations->firstWhere('id', $order->warehouse_location_id);
            if (! $location) throw ValidationException::withMessages(['warehouse_location_id' => 'La ubicación original del pedido ya no está disponible.']);
            $shipment = DeliveryShipment::query()->create([
                'delivery_order_id' => $order->id, 'user_id' => $userId,
                'shipment_key' => $data['shipment_key'], 'payload_hash' => $payloadHash,
                'notes' => $data['notes'] ?? null, 'delivered_at' => now(),
            ]);
            if (! empty($data['guide'])) {
                $guide = $data['guide'];
                if (\App\Models\DeliveryGuide::query()->where('series', $guide['series'])->where('number', $guide['number'])->exists()) {
                    throw ValidationException::withMessages(['guide.number' => 'Esa Guía de Remisión ya fue registrada.']);
                }
                $shipment->guide()->create($guide + ['user_id' => $userId, 'status' => 'registered_external']);
            }
            $saleLines = [];
            $deliveryMovementKeys = [];
            foreach ($data['lines'] as $index => $input) {
                $line = DeliveryOrderLine::query()->where('delivery_order_id', $order->id)->lockForUpdate()->findOrFail($input['delivery_order_line_id']);
                $quantity = BigDecimal::of((string) $input['quantity'])->toScale(6, RoundingMode::UNNECESSARY);
                $remainingPicked = BigDecimal::of($line->picked_quantity)->minus($line->delivered_quantity)->toScale(6);
                if (! $quantity->isPositive() || $quantity->compareTo($remainingPicked) > 0) throw ValidationException::withMessages(['lines' => 'La cantidad supera lo preparado y pendiente por entregar.']);
                $product = Product::query()->findOrFail($line->product_id);
                $presentation = ProductPresentation::query()->findOrFail($line->product_presentation_id);
                if ((int) $presentation->price_cents !== (int) $line->unit_price_cents) throw new ConflictHttpException('El precio cambió desde que se registró el pedido. Cancela y vuelve a ingresarlo para conservar el precio acordado.');
                $movementKey = 'delivery-' . substr(hash('sha256', $data['shipment_key'] . ':' . $index), 0, 56);
                $this->inventory->consumeReserved($product, $location, $presentation, (string) $quantity,
                    'Entrega ' . $order->order_number, $movementKey, $userId);
                $deliveryMovementKeys[] = $movementKey;
                $line->forceFill(['delivered_quantity' => (string) BigDecimal::of($line->delivered_quantity)->plus($quantity)->toScale(6)])->save();
                $shipment->lines()->create(['delivery_order_line_id' => $line->id, 'quantity' => (string) $quantity]);
                $saleLines[] = ['product_id' => $product->id, 'presentation_id' => $presentation->id, 'quantity' => (string) $quantity];
            }
            $order->refresh()->load('lines');
            $allDelivered = $order->lines->every(fn (DeliveryOrderLine $line) => BigDecimal::of($line->delivered_quantity)->compareTo($line->ordered_quantity) >= 0);
            $order->forceFill(['status' => $allDelivered ? 'delivered' : 'partially_delivered'])->save();
            $sale = $this->sales->create([
                'operation_key' => 'delivery-sale-' . substr(hash('sha256', $data['shipment_key']), 0, 48),
                'cash_session_id' => $session->id, 'warehouse_location_id' => $location->id, 'customer_id' => $order->customer_id,
                'lines' => $saleLines, 'payments' => [],
            ], $userId, true);
            $shipment->forceFill(['sale_id' => $sale->id])->save();
            DB::table('inventory_movements')->whereIn('operation_key', $deliveryMovementKeys)->update(['sale_id' => $sale->id]);
            return $shipment->load(['lines', 'sale', 'order', 'guide']);
        }, 3);
    }

    public function cancel(DeliveryOrder $order, int $userId): DeliveryOrder
    {
        return DB::transaction(function () use ($order, $userId): DeliveryOrder {
            $order = DeliveryOrder::query()->with('lines')->lockForUpdate()->findOrFail($order->id);
            if (! in_array($order->status, ['confirmed', 'partially_picked', 'picked', 'partially_delivered'], true)) throw new ConflictHttpException('El pedido ya está cerrado.');
            $warehouse = Warehouse::query()->with('locations')->findOrFail($order->warehouse_id);
            $location = $warehouse->locations->firstWhere('is_default', true) ?? $warehouse->locations->firstOrFail();
            foreach ($order->lines as $line) {
                $remaining = BigDecimal::of($line->ordered_quantity)->minus($line->delivered_quantity)->toScale(6);
                if (! $remaining->isPositive()) continue;
                $baseQuantity = $remaining->multipliedBy($line->factor_to_base)->toScale(6, RoundingMode::UNNECESSARY);
                $balance = StockBalance::query()->where('warehouse_location_id', $location->id)->where('product_id', $line->product_id)->lockForUpdate()->firstOrFail();
                $reserved = BigDecimal::of($balance->reserved_quantity)->toScale(6);
                if ($reserved->compareTo($baseQuantity) < 0) throw new ConflictHttpException('La reserva no coincide con el saldo disponible; requiere conciliación.');
                $balance->forceFill(['reserved_quantity' => (string) $reserved->minus($baseQuantity)->toScale(6)])->save();
            }
            $order->forceFill(['status' => 'cancelled', 'cancelled_by' => $userId, 'cancelled_at' => now()])->save();
            return $order;
        }, 3);
    }
}
