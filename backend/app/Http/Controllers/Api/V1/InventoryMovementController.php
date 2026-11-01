<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductPresentation;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\InventoryService;
use App\Services\PhysicalCountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class InventoryMovementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $warehouseId = $request->integer('warehouse_id');
        $movements = InventoryMovement::query()
            ->with(['product:id,sku,name,base_unit', 'presentation:id,name', 'location:id,warehouse_id,name'])
            ->when($warehouseId, fn ($query) => $query->whereHas('location', fn ($location) => $location->where('warehouse_id', $warehouseId)))
            ->latest('id')->limit(100)->get();

        return response()->json(['data' => $movements]);
    }

    public function store(Request $request, InventoryService $inventory): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'location_id' => ['nullable', 'integer', 'exists:warehouse_locations,id'],
            'presentation_id' => ['required', 'integer', 'exists:product_presentations,id'],
            'type' => ['required', 'in:opening,receipt,adjustment_in,adjustment_out,damaged'],
            'quantity' => ['required', 'regex:/^\d{1,12}(?:\.\d{1,6})?$/'],
            'unit_cost_cents' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'reason' => ['required', 'string', 'min:3', 'max:240'],
            'operation_key' => ['required', 'string', 'max:100'],
        ]);

        $product = Product::query()->where('is_active', true)->findOrFail($data['product_id']);
        $presentation = ProductPresentation::query()
            ->where('product_id', $product->id)->where('is_active', true)->findOrFail($data['presentation_id']);
        $warehouse = Warehouse::query()->where('is_active', true)->with('locations')->findOrFail($data['warehouse_id']);
        $location = isset($data['location_id'])
            ? WarehouseLocation::query()->where('warehouse_id', $warehouse->id)->findOrFail($data['location_id'])
            : ($warehouse->locations->firstWhere('is_default', true) ?? $warehouse->locations->first());
        abort_if(! $location, 422, 'El almacén todavía no tiene una ubicación activa.');

        $movement = $inventory->record(
            $product, $location, $presentation, $data['type'], $data['quantity'],
            trim($data['reason']), trim($data['operation_key']), $request->user()->id,
            isset($data['unit_cost_cents']) ? (string) $data['unit_cost_cents'] : null,
        );

        return response()->json(['data' => $movement->load(['product', 'presentation', 'location'])], 201);
    }

    public function physicalCount(Request $request, PhysicalCountService $counts): JsonResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'location_id' => ['nullable', 'integer', 'exists:warehouse_locations,id'],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'counted_quantity' => ['required', 'regex:/^\d{1,12}(?:\.\d{1,6})?$/'],
            'reason' => ['required', 'string', 'min:3', 'max:240'],
            'operation_key' => ['required', 'string', 'max:100'],
        ]);
        $count = $counts->count($data, (int) $request->user()->id);
        return response()->json(['data' => $count->load(['product', 'movement'])], 201);
    }
}
