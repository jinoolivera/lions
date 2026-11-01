<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductPresentation;
use App\Models\StockBalance;
use App\Models\StockTransfer;
use App\Models\StockTransferLine;
use App\Models\StockTransferReceipt;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class StockTransferController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $warehouseId = $request->integer('warehouse_id');
        $transfers = StockTransfer::query()->with(['source:id,name', 'destination:id,name', 'lines', 'receipts.lines'])
            ->when($warehouseId, fn ($query) => $query->where(fn ($q) => $q->where('source_warehouse_id', $warehouseId)->orWhere('destination_warehouse_id', $warehouseId)))
            ->latest('id')->limit(100)->get();
        return response()->json(['data' => $transfers]);
    }

    public function dispatch(Request $request, InventoryService $inventory): JsonResponse
    {
        $data = $request->validate([
            'operation_key' => ['required', 'string', 'max:100'], 'source_warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'destination_warehouse_id' => ['required', 'integer', 'different:source_warehouse_id', 'exists:warehouses,id'],
            'source_location_id' => ['sometimes', 'integer', 'exists:warehouse_locations,id'],
            'destination_location_id' => ['sometimes', 'integer', 'exists:warehouse_locations,id'],
            'notes' => ['nullable', 'string', 'max:2000'], 'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.presentation_id' => ['required', 'integer', 'exists:product_presentations,id'],
            'lines.*.quantity' => ['required', 'regex:/^\d{1,12}(?:\.\d{1,6})?$/'],
        ]);
        $hash = hash('sha256', json_encode([
            'source' => $data['source_warehouse_id'], 'destination' => $data['destination_warehouse_id'],
            'notes' => $data['notes'] ?? null, 'source_location_id' => $data['source_location_id'] ?? null,
            'destination_location_id' => $data['destination_location_id'] ?? null, 'lines' => $data['lines'],
        ], JSON_THROW_ON_ERROR));
        $transfer = DB::transaction(function () use ($request, $data, $hash, $inventory): StockTransfer {
            $existing = StockTransfer::query()->where('operation_key', $data['operation_key'])->first();
            if ($existing) {
                if (! hash_equals($existing->payload_hash, $hash)) throw new ConflictHttpException('La clave ya fue usada para otro traslado.');
                return $existing->load(['source', 'destination', 'lines', 'receipts.lines']);
            }
            $source = Warehouse::query()->where('is_active', true)->with('locations')->findOrFail($data['source_warehouse_id']);
            $destination = Warehouse::query()->where('is_active', true)->with('locations')->findOrFail($data['destination_warehouse_id']);
            $sourceLocation = isset($data['source_location_id']) ? $source->locations->firstWhere('id', (int) $data['source_location_id']) : ($source->locations->firstWhere('is_default', true) ?? $source->locations->first());
            $destinationLocation = isset($data['destination_location_id']) ? $destination->locations->firstWhere('id', (int) $data['destination_location_id']) : ($destination->locations->firstWhere('is_default', true) ?? $destination->locations->first());
            if (! $sourceLocation || ! $destinationLocation) throw ValidationException::withMessages(['source_location_id' => 'Selecciona ubicaciones válidas para ambos almacenes.']);
            $transfer = StockTransfer::query()->create([
                'source_warehouse_id' => $source->id, 'destination_warehouse_id' => $destination->id,
                'source_location_id' => $sourceLocation->id, 'destination_location_id' => $destinationLocation->id,
                'user_id' => $request->user()->id, 'transfer_number' => 'TR-'.now()->format('ymd').'-'.Str::upper(Str::random(7)),
                'operation_key' => $data['operation_key'], 'payload_hash' => $hash, 'status' => 'in_transit',
                'notes' => $data['notes'] ?? null, 'shipped_at' => now(),
            ]);
            foreach ($data['lines'] as $index => $entry) {
                $product = Product::query()->where('is_active', true)->findOrFail($entry['product_id']);
                $presentation = ProductPresentation::query()->where('product_id', $product->id)->where('is_active', true)->findOrFail($entry['presentation_id']);
                $quantity = BigDecimal::of((string) $entry['quantity'])->toScale(6, RoundingMode::UNNECESSARY);
                $baseQuantity = $quantity->multipliedBy($presentation->factor_to_base)->toScale(6, RoundingMode::UNNECESSARY);
                if (! $quantity->isPositive() || (! $product->allows_fractional_quantity && $baseQuantity->stripTrailingZeros()->getScale() > 0)) {
                    throw ValidationException::withMessages(['lines' => "La cantidad de {$product->name} no coincide con su unidad base."]);
                }
                $balance = StockBalance::query()->where('warehouse_location_id', $sourceLocation->id)->where('product_id', $product->id)->lockForUpdate()->first();
                if (! $balance) throw ValidationException::withMessages(['lines' => "No hay saldo en el almacén de origen para {$product->name}."]);
                $unitCostBase = $balance->average_unit_cost_cents === null ? null : BigDecimal::of($balance->average_unit_cost_cents)->toScale(6);
                $costPerPresentation = $unitCostBase?->multipliedBy(BigDecimal::of($presentation->factor_to_base))->toScale(6, RoundingMode::HALF_UP)->__toString();
                $inventory->record($product, $sourceLocation, $presentation, 'transfer_out', (string) $quantity,
                    "Despacho de traslado {$transfer->transfer_number}", 'transfer:'.$data['operation_key'].':'.$index,
                    $request->user()->id, $costPerPresentation);
                StockTransferLine::query()->create([
                    'stock_transfer_id' => $transfer->id, 'product_id' => $product->id,
                    'product_presentation_id' => $presentation->id, 'product_name_snapshot' => $product->name,
                    'presentation_name_snapshot' => $presentation->name, 'factor_to_base' => $presentation->factor_to_base,
                    'shipped_quantity' => (string) $quantity, 'received_quantity' => '0.000000',
                    'unit_cost_base_cents' => $unitCostBase === null ? null : (string) $unitCostBase,
                ]);
            }
            return $transfer->load(['source', 'destination', 'lines', 'receipts.lines']);
        }, 3);
        return response()->json(['data' => $transfer], 201);
    }

    public function receive(Request $request, StockTransfer $stockTransfer, InventoryService $inventory): JsonResponse
    {
        $data = $request->validate([
            'operation_key' => ['required', 'string', 'max:100'], 'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.transfer_line_id' => ['required', 'integer', 'distinct', 'exists:stock_transfer_lines,id'],
            'lines.*.quantity' => ['required', 'regex:/^\d{1,12}(?:\.\d{1,6})?$/'],
        ]);
        $hash = hash('sha256', json_encode(['transfer' => $stockTransfer->id, 'lines' => $data['lines']], JSON_THROW_ON_ERROR));
        $transfer = DB::transaction(function () use ($request, $stockTransfer, $data, $hash, $inventory): StockTransfer {
            $existing = StockTransferReceipt::query()->where('operation_key', $data['operation_key'])->first();
            if ($existing) {
                if (! hash_equals($existing->payload_hash, $hash)) throw new ConflictHttpException('La clave ya fue usada para otra recepción de traslado.');
                return $stockTransfer->fresh()->load(['source', 'destination', 'lines', 'receipts.lines']);
            }
            $transfer = StockTransfer::query()->whereKey($stockTransfer->id)->lockForUpdate()->firstOrFail();
            if (in_array($transfer->status, ['received', 'cancelled'], true)) throw new ConflictHttpException('Este traslado no admite más recepciones.');
            $destination = Warehouse::query()->where('is_active', true)->with('locations')->findOrFail($transfer->destination_warehouse_id);
            $location = $destination->locations->firstWhere('id', $transfer->destination_location_id);
            if (! $location) throw ValidationException::withMessages(['destination_location_id' => 'La ubicación de destino original ya no está disponible.']);
            $receipt = StockTransferReceipt::query()->create([
                'stock_transfer_id' => $transfer->id, 'user_id' => $request->user()->id,
                'operation_key' => $data['operation_key'], 'payload_hash' => $hash, 'received_at' => now(),
            ]);
            foreach ($data['lines'] as $entry) {
                $line = StockTransferLine::query()->whereKey($entry['transfer_line_id'])->where('stock_transfer_id', $transfer->id)->lockForUpdate()->firstOrFail();
                $quantity = BigDecimal::of((string) $entry['quantity'])->toScale(6, RoundingMode::UNNECESSARY);
                $remaining = BigDecimal::of($line->shipped_quantity)->minus(BigDecimal::of($line->received_quantity));
                if (! $quantity->isPositive() || $quantity->compareTo($remaining) > 0) throw ValidationException::withMessages(['lines' => "La recepción excede el saldo en tránsito de {$line->product_name_snapshot}."]);
                $product = Product::query()->findOrFail($line->product_id);
                $presentation = ProductPresentation::query()->findOrFail($line->product_presentation_id);
                $costPerPresentation = $line->unit_cost_base_cents === null ? null
                    : BigDecimal::of($line->unit_cost_base_cents)->multipliedBy(BigDecimal::of($line->factor_to_base))->toScale(6, RoundingMode::HALF_UP)->__toString();
                $inventory->record($product, $location, $presentation, 'transfer_in', (string) $quantity,
                    "Recepción de traslado {$transfer->transfer_number}", 'transfer:'.$data['operation_key'].':'.$line->id,
                    $request->user()->id, $costPerPresentation);
                $line->forceFill(['received_quantity' => (string) BigDecimal::of($line->received_quantity)->plus($quantity)->toScale(6)])->save();
                $receipt->lines()->create(['stock_transfer_line_id' => $line->id, 'quantity' => (string) $quantity]);
            }
            $lines = StockTransferLine::query()->where('stock_transfer_id', $transfer->id)->get();
            $complete = $lines->every(fn (StockTransferLine $line) => BigDecimal::of($line->received_quantity)->compareTo(BigDecimal::of($line->shipped_quantity)) >= 0);
            $transfer->forceFill(['status' => $complete ? 'received' : 'partially_received', 'received_at' => $complete ? now() : null])->save();
            return $transfer->fresh()->load(['source', 'destination', 'lines', 'receipts.lines']);
        }, 3);
        return response()->json(['data' => $transfer]);
    }
}
