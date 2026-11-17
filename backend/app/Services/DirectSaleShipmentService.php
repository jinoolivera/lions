<?php

namespace App\Services;

use App\Models\DeliveryGuide;
use App\Models\DeliveryShipment;
use App\Models\Sale;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class DirectSaleShipmentService
{
    public function prepare(Sale $sale, array $data, int $userId): DeliveryShipment
    {
        $payloadHash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($sale, $data, $userId, $payloadHash): DeliveryShipment {
            $existing = DeliveryShipment::query()->where('shipment_key', $data['shipment_key'])->first();
            if ($existing) {
                if ((int) $existing->sale_id !== (int) $sale->id || ! hash_equals($existing->payload_hash, $payloadHash)) {
                    throw new ConflictHttpException('La clave del envío ya fue usada con datos distintos.');
                }

                return $existing->load(['lines.saleLine', 'guide']);
            }

            $sale = Sale::query()->with(['lines', 'shipments.lines'])->lockForUpdate()->findOrFail($sale->id);
            if (in_array($sale->status, ['cancelled', 'returned'], true)) {
                throw new ConflictHttpException('La venta anulada o devuelta no admite envíos.');
            }
            if ($sale->shipments->contains(fn (DeliveryShipment $shipment) => $shipment->delivery_order_id !== null)) {
                throw ValidationException::withMessages(['sale' => 'Esta venta ya corresponde a un despacho de pedido.']);
            }

            $requested = [];
            foreach ($data['lines'] as $input) {
                $lineId = (int) $input['sale_line_id'];
                $line = $sale->lines->firstWhere('id', $lineId);
                if (! $line || isset($requested[$lineId])) {
                    throw ValidationException::withMessages(['lines' => 'Selecciona cada producto de la venta una sola vez.']);
                }
                $quantity = BigDecimal::of((string) $input['quantity'])->toScale(6, RoundingMode::UNNECESSARY);
                if (! $quantity->isPositive()) {
                    throw ValidationException::withMessages(['lines' => 'La cantidad a enviar debe ser mayor que cero.']);
                }
                $assigned = BigDecimal::zero()->toScale(6);
                foreach ($sale->shipments->where('status', '!=', 'cancelled') as $shipment) {
                    foreach ($shipment->lines->where('sale_line_id', $lineId) as $shipmentLine) {
                        $assigned = $assigned->plus($shipmentLine->quantity);
                    }
                }
                $available = BigDecimal::of($line->quantity)->minus($line->returned_quantity ?? 0)->minus($assigned);
                if ($quantity->compareTo($available) > 0) {
                    throw ValidationException::withMessages(['lines' => "La cantidad de {$line->product_name_snapshot} supera lo disponible para enviar."]);
                }
                $requested[$lineId] = (string) $quantity;
            }

            $shipment = $sale->shipments()->create([
                'user_id' => $userId,
                'shipment_key' => $data['shipment_key'],
                'payload_hash' => $payloadHash,
                'status' => 'prepared',
                'recipient_name' => trim($data['recipient_name']),
                'delivery_address' => trim($data['delivery_address']),
                'delivery_contact' => $data['delivery_contact'] ?? null,
                'notes' => $data['notes'] ?? null,
                'guide_required' => (bool) $data['guide_required'],
            ]);
            foreach ($requested as $lineId => $quantity) {
                $shipment->lines()->create(['sale_line_id' => $lineId, 'quantity' => $quantity]);
            }

            return $shipment->load(['lines.saleLine', 'guide']);
        }, 3);
    }

    public function dispatch(Sale $sale, DeliveryShipment $shipment, array $data, int $userId): DeliveryShipment
    {
        return DB::transaction(function () use ($sale, $shipment, $data, $userId): DeliveryShipment {
            $sale = Sale::query()->with('lines')->lockForUpdate()->findOrFail($sale->id);
            $shipment = DeliveryShipment::query()->with(['lines.saleLine', 'guide'])->lockForUpdate()->findOrFail($shipment->id);
            if ((int) $shipment->sale_id !== (int) $sale->id || $shipment->delivery_order_id !== null) {
                throw new ConflictHttpException('El envío no corresponde a esta venta directa.');
            }
            if ($shipment->status !== 'prepared' || in_array($sale->status, ['cancelled', 'returned'], true)) {
                throw new ConflictHttpException('Este envío ya no se puede despachar.');
            }

            $dispatchedLines = DB::table('delivery_shipment_lines')
                ->join('delivery_shipments', 'delivery_shipments.id', '=', 'delivery_shipment_lines.delivery_shipment_id')
                ->where('delivery_shipments.sale_id', $sale->id)
                ->where('delivery_shipments.status', 'dispatched')
                ->where('delivery_shipments.id', '!=', $shipment->id)
                ->whereNotNull('delivery_shipment_lines.sale_line_id')
                ->get(['delivery_shipment_lines.sale_line_id', 'delivery_shipment_lines.quantity']);
            $dispatchedQuantities = [];
            foreach ($dispatchedLines as $line) {
                $dispatchedQuantities[$line->sale_line_id] = (string) BigDecimal::of($dispatchedQuantities[$line->sale_line_id] ?? '0')
                    ->plus($line->quantity)->toScale(6);
            }
            foreach ($shipment->lines as $shipmentLine) {
                $saleLine = $sale->lines->firstWhere('id', $shipmentLine->sale_line_id);
                $available = BigDecimal::of($saleLine->quantity)->minus($saleLine->returned_quantity ?? 0)
                    ->minus($dispatchedQuantities[$shipmentLine->sale_line_id] ?? '0');
                if (BigDecimal::of($shipmentLine->quantity)->compareTo($available) > 0) {
                    throw ValidationException::withMessages(['lines' => "La devolución de {$saleLine->product_name_snapshot} dejó este envío sin cantidad suficiente. Cancela la preparación y crea otra."]);
                }
            }

            $guideRequired = (bool) $data['guide_required'];
            if ($guideRequired && empty($data['guide'])) {
                throw ValidationException::withMessages(['guide' => 'Registra la GRE ya emitida antes de despachar.']);
            }
            if (! empty($data['guide'])) {
                $guide = $data['guide'];
                if (DeliveryGuide::query()->where('series', $guide['series'])->where('number', $guide['number'])->exists()) {
                    throw ValidationException::withMessages(['guide.number' => 'Esa Guía de Remisión ya fue registrada.']);
                }
                $shipment->guide()->create($guide + ['user_id' => $userId, 'status' => 'registered_external']);
            }

            $shipment->forceFill(['status' => 'dispatched', 'guide_required' => $guideRequired || ! empty($data['guide']), 'delivered_at' => now()])->save();

            return $shipment->load(['lines.saleLine', 'guide']);
        }, 3);
    }

    public function cancel(Sale $sale, DeliveryShipment $shipment): DeliveryShipment
    {
        return DB::transaction(function () use ($sale, $shipment): DeliveryShipment {
            $shipment = DeliveryShipment::query()->lockForUpdate()->findOrFail($shipment->id);
            if ((int) $shipment->sale_id !== (int) $sale->id || $shipment->delivery_order_id !== null || $shipment->status !== 'prepared') {
                throw new ConflictHttpException('Solo se puede cancelar un envío directo preparado.');
            }
            $shipment->forceFill(['status' => 'cancelled'])->save();

            return $shipment->load(['lines.saleLine', 'guide']);
        }, 3);
    }
}
