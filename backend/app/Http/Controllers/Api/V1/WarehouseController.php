<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class WarehouseController extends Controller
{
    public function index(): JsonResponse
    {
        $warehouses = Warehouse::query()->where('is_active', true)->with('locations')->orderBy('name')->get()
            ->map(fn (Warehouse $warehouse): array => [
                'id' => $warehouse->id, 'code' => $warehouse->code, 'name' => $warehouse->name,
                'location_id' => $warehouse->locations->firstWhere('is_default', true)?->id ?? $warehouse->locations->first()?->id,
                'location_name' => $warehouse->locations->firstWhere('is_default', true)?->name ?? $warehouse->locations->first()?->name,
                'locations' => $warehouse->locations->map(fn ($location): array => [
                    'id' => $location->id, 'code' => $location->code, 'name' => $location->name, 'is_default' => $location->is_default,
                ])->values(),
            ]);

        return response()->json(['data' => $warehouses]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32', 'unique:warehouses,code'],
            'name' => ['required', 'string', 'max:120'],
            'location_name' => ['required', 'string', 'max:120'],
        ]);
        $warehouse = DB::transaction(function () use ($data): Warehouse {
            $warehouse = Warehouse::query()->create(['code' => strtoupper(trim($data['code'])), 'name' => trim($data['name'])]);
            $warehouse->locations()->create(['code' => 'DEFAULT', 'name' => trim($data['location_name']), 'is_default' => true]);

            return $warehouse;
        });

        return response()->json(['data' => $warehouse->load('locations')], 201);
    }

    public function storeLocation(Request $request, Warehouse $warehouse): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32', Rule::unique('warehouse_locations', 'code')->where('warehouse_id', $warehouse->id)],
            'name' => ['required', 'string', 'max:120'], 'is_default' => ['sometimes', 'boolean'],
        ]);
        $location = DB::transaction(function () use ($data, $warehouse): WarehouseLocation {
            $lockedWarehouse = Warehouse::query()->lockForUpdate()->findOrFail($warehouse->id);
            $isDefault = (bool) ($data['is_default'] ?? false);
            if ($isDefault) $lockedWarehouse->locations()->update(['is_default' => false]);
            return $lockedWarehouse->locations()->create([
                'code' => strtoupper(trim($data['code'])), 'name' => trim($data['name']), 'is_default' => $isDefault,
            ]);
        }, 3);
        return response()->json(['data' => $location], 201);
    }

    public function updateLocation(Request $request, Warehouse $warehouse, WarehouseLocation $location): JsonResponse
    {
        abort_unless((int) $location->warehouse_id === (int) $warehouse->id, 404);
        $data = $request->validate([
            'code' => ['sometimes', 'required', 'string', 'max:32', Rule::unique('warehouse_locations', 'code')->where('warehouse_id', $warehouse->id)->ignore($location->id)],
            'name' => ['sometimes', 'required', 'string', 'max:120'], 'is_default' => ['sometimes', 'boolean'],
        ]);
        $updated = DB::transaction(function () use ($data, $warehouse, $location): WarehouseLocation {
            $lockedWarehouse = Warehouse::query()->lockForUpdate()->findOrFail($warehouse->id);
            $lockedLocation = WarehouseLocation::query()->lockForUpdate()->where('warehouse_id', $lockedWarehouse->id)->findOrFail($location->id);
            if (array_key_exists('is_default', $data) && $data['is_default']) {
                $lockedWarehouse->locations()->where('id', '<>', $lockedLocation->id)->update(['is_default' => false]);
            } elseif (($data['is_default'] ?? null) === false && $lockedLocation->is_default) {
                throw \Illuminate\Validation\ValidationException::withMessages(['is_default' => 'Define otra ubicación predeterminada antes de quitar esta.']);
            }
            if (isset($data['code'])) $data['code'] = strtoupper(trim($data['code']));
            if (isset($data['name'])) $data['name'] = trim($data['name']);
            $lockedLocation->update($data);
            return $lockedLocation->fresh();
        }, 3);
        return response()->json(['data' => $updated]);
    }
}
