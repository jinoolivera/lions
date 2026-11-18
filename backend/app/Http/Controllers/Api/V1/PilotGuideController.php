<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DeliveryShipment;
use App\Models\Sale;
use App\Services\PilotGuidePdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

class PilotGuideController extends Controller
{
    private function check(Sale $sale, DeliveryShipment $shipment): void
    {
        abort_unless(config('lions.pilot_mode'), 404);
        abort_unless((int) $shipment->sale_id === (int) $sale->id && $shipment->status !== 'cancelled', 404);
    }

    public function link(Sale $sale, DeliveryShipment $shipment): JsonResponse
    {
        $this->check($sale, $shipment);
        $path = URL::temporarySignedRoute('pilot-guides.pdf', now()->addDays(7), ['shipment' => $shipment->id], absolute: false);

        return response()->json(['url' => rtrim(config('app.url'), '/').$path]);
    }

    public function signedPdf(DeliveryShipment $shipment, PilotGuidePdf $pdf)
    {
        abort_unless(config('lions.pilot_mode') && $shipment->sale_id && $shipment->status !== 'cancelled', 404);

        return response($pdf->render($shipment), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="SIMULACION-ENVIO-'.$shipment->id.'.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function email(Request $request, Sale $sale, DeliveryShipment $shipment, PilotGuidePdf $pdf): JsonResponse
    {
        $this->check($sale, $shipment);
        $data = $request->validate(['email' => ['required', 'email', 'max:254']]);
        if (config('mail.default') !== 'resend' || ! config('services.resend.key')) {
            return response()->json(['message' => 'Configura Resend para enviar el PDF por correo.'], 503);
        }
        Mail::send('emails.pilot-guide', ['shipment' => $shipment], function ($message) use ($data, $shipment, $pdf) {
            $message->to($data['email'])->subject('Simulación interna de envío #'.$shipment->id.' · U.LIONS')
                ->attachData($pdf->render($shipment), 'SIMULACION-ENVIO-'.$shipment->id.'.pdf', ['mime' => 'application/pdf']);
        });

        return response()->json(['message' => 'Simulación PDF enviada por correo.']);
    }
}
