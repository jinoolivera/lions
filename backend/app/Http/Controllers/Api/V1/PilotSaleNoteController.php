<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PilotSaleNote;
use App\Support\BranchAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PilotSaleNoteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless(config('lions.pilot_mode'), 404);
        $warehouseId = $request->integer('warehouse_id');
        if ($warehouseId) BranchAccess::requireWarehouse($request->user(), $warehouseId);
        $query = PilotSaleNote::query()->whereIn('warehouse_id', BranchAccess::warehouseIds($request->user()));
        if ($warehouseId) $query->where('warehouse_id', $warehouseId);

        return response()->json(['data' => $query->latest('sold_at')->limit(200)->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless(config('lions.pilot_mode'), 404);
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'product_name' => ['required', 'string', 'max:180'],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'unit_price_cents' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'customer_name' => ['nullable', 'string', 'max:180'],
            'payment_method' => ['required', 'in:cash,card,transfer,other'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);
        BranchAccess::requireWarehouse($request->user(), $data['warehouse_id']);
        $note = PilotSaleNote::query()->create($data + ['user_id' => $request->user()->id, 'sold_at' => now()]);

        return response()->json(['data' => $note], 201);
    }
}
