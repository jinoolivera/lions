<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DeliveryShipment;
use App\Models\Sale;
use App\Services\DirectSaleShipmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DirectSaleShipmentController extends Controller
{
    public function prepare(Request $request, Sale $sale, DirectSaleShipmentService $service): JsonResponse
    {
        $data = $request->validate([
            'shipment_key' => ['required', 'string', 'max:100'],
            'recipient_name' => ['required', 'string', 'max:180'],
            'delivery_address' => ['required', 'string', 'max:240'],
            'delivery_contact' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'guide_required' => ['required', 'boolean'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.sale_line_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'regex:/^\d{1,12}(?:\.\d{1,6})?$/'],
        ]);

        return response()->json(['data' => $service->prepare($sale, $data, (int) $request->user()->id)], 201);
    }

    public function dispatch(Request $request, Sale $sale, DeliveryShipment $shipment, DirectSaleShipmentService $service): JsonResponse
    {
        $data = $request->validate([
            'guide_required' => ['required', 'boolean'],
            'guide.series' => ['required_with:guide.number,guide.issue_date,guide.issued_via', 'nullable', 'regex:/^(EG07|T[A-Z0-9]{3})$/'],
            'guide.number' => ['required_with:guide.series', 'regex:/^[0-9]{1,8}$/'],
            'guide.issue_date' => ['required_with:guide.series', 'date_format:Y-m-d', 'before_or_equal:today'],
            'guide.issued_via' => ['required_with:guide.series', 'in:see_sol,see_contributor'],
        ]);
        if (! empty($data['guide']['series']) && (($data['guide']['issued_via'] === 'see_sol' && $data['guide']['series'] !== 'EG07') || ($data['guide']['issued_via'] === 'see_contributor' && ! str_starts_with($data['guide']['series'], 'T')))) {
            throw ValidationException::withMessages(['guide.series' => 'La serie no corresponde al canal de emisión seleccionado.']);
        }

        return response()->json(['data' => $service->dispatch($sale, $shipment, $data, (int) $request->user()->id)]);
    }

    public function cancel(Sale $sale, DeliveryShipment $shipment, DirectSaleShipmentService $service): JsonResponse
    {
        return response()->json(['data' => $service->cancel($sale, $shipment)]);
    }
}
