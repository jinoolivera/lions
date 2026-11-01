<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DeliveryOrder;
use App\Models\Warehouse;
use App\Services\DeliveryOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeliveryOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $warehouseId = $request->integer('warehouse_id');
        return response()->json(['data' => DeliveryOrder::query()->with(['warehouse', 'customer', 'lines', 'shipments.sale', 'shipments.guide'])->when($warehouseId, fn ($query) => $query->where('warehouse_id', $warehouseId))->latest('ordered_at')->limit(200)->get()]);
    }

    public function store(Request $request, DeliveryOrderService $service): JsonResponse
    {
        $request->merge(['source' => $request->input('source', 'other')]);
        $data = $request->validate([
            'operation_key' => ['required', 'string', 'max:100'], 'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'warehouse_location_id' => ['sometimes', 'integer', 'exists:warehouse_locations,id'],
            'customer_id' => ['required', 'integer', 'exists:customers,id'], 'notes' => ['nullable', 'string', 'max:2000'],
            'source' => ['required', 'in:whatsapp,in_person,phone,other'], 'delivery_address' => ['nullable', 'string', 'max:2000'],
            'delivery_contact' => ['nullable', 'string', 'max:180'], 'dispatch_responsible' => ['nullable', 'string', 'max:180'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.presentation_id' => ['required', 'integer', 'exists:product_presentations,id'],
            'lines.*.quantity' => ['required', 'regex:/^\d{1,12}(?:\.\d{1,6})?$/'],
        ]);
        return response()->json(['data' => $service->create($data, (int) $request->user()->id)], 201);
    }

    public function pick(Request $request, DeliveryOrder $deliveryOrder, DeliveryOrderService $service): JsonResponse
    {
        $data = $request->validate([
            'operation_key' => ['required', 'string', 'max:100'], 'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.delivery_order_line_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'regex:/^\d{1,12}(?:\.\d{1,6})?$/'],
        ]);
        return response()->json(['data' => $service->pick($deliveryOrder, $data, (int) $request->user()->id)]);
    }

    public function deliver(Request $request, DeliveryOrder $deliveryOrder, DeliveryOrderService $service): JsonResponse
    {
        $data = $request->validate([
            'shipment_key' => ['required', 'string', 'max:100'], 'notes' => ['nullable', 'string', 'max:2000'],
            'guide.series' => ['nullable', 'regex:/^(EG07|T[A-Z0-9]{3})$/'],
            'guide.number' => ['required_with:guide.series', 'regex:/^[0-9]{1,8}$/'],
            'guide.issue_date' => ['required_with:guide.series', 'date_format:Y-m-d', 'before_or_equal:today'],
            'guide.issued_via' => ['required_with:guide.series', 'in:see_sol,see_contributor'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.delivery_order_line_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'regex:/^\d{1,12}(?:\.\d{1,6})?$/'],
        ]);
        if (isset($data['guide']) && (($data['guide']['issued_via'] === 'see_sol' && $data['guide']['series'] !== 'EG07') || ($data['guide']['issued_via'] === 'see_contributor' && ! str_starts_with($data['guide']['series'], 'T')))) {
            throw \Illuminate\Validation\ValidationException::withMessages(['guide.series' => 'La serie no corresponde al canal de emisión seleccionado.']);
        }
        return response()->json(['data' => $service->deliver($deliveryOrder, $data, (int) $request->user()->id)], 201);
    }

    public function cancel(Request $request, DeliveryOrder $deliveryOrder, DeliveryOrderService $service): JsonResponse
    {
        return response()->json(['data' => $service->cancel($deliveryOrder, (int) $request->user()->id)]);
    }
}
