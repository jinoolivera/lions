<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Mail\DeliveryGuidePdfMail;
use App\Models\DeliveryGuide;
use App\Models\DeliveryShipment;
use App\Models\Sale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class DeliveryGuidePdfController extends Controller
{
    private function guideForSale(Sale $sale, DeliveryShipment $shipment): DeliveryGuide
    {
        abort_unless((int) $shipment->sale_id === (int) $sale->id, 404);

        return $shipment->guide()->firstOrFail();
    }

    public function upload(Request $request, Sale $sale, DeliveryShipment $shipment): JsonResponse
    {
        $guide = $this->guideForSale($sale, $shipment);
        $request->validate(['pdf' => ['required', 'file', 'mimes:pdf', 'max:5120']]);
        $path = $request->file('pdf')->store('guides/official', 'local');
        $oldPath = $guide->pdf_path;
        try {
            $guide->forceFill(['pdf_path' => $path])->save();
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }
        if ($oldPath) {
            Storage::disk('local')->delete($oldPath);
        }

        return response()->json(['data' => $guide->fresh()]);
    }

    public function link(Sale $sale, DeliveryShipment $shipment): JsonResponse
    {
        $guide = $this->guideForSale($sale, $shipment);
        abort_unless($guide->pdf_path && Storage::disk('local')->exists($guide->pdf_path), 422, 'Adjunta primero el PDF de la guía emitida en SUNAT.');
        $path = URL::temporarySignedRoute('guides.pdf', now()->addDays(7), ['guide' => $guide->id], absolute: false);

        return response()->json(['url' => rtrim(config('app.url'), '/').$path]);
    }

    public function email(Request $request, Sale $sale, DeliveryShipment $shipment): JsonResponse
    {
        $guide = $this->guideForSale($sale, $shipment);
        $data = $request->validate(['email' => ['required', 'email', 'max:254']]);
        abort_unless($guide->pdf_path && Storage::disk('local')->exists($guide->pdf_path), 422, 'Adjunta primero el PDF de la guía emitida en SUNAT.');
        if (config('mail.default') !== 'resend' || ! config('services.resend.key')) {
            return response()->json(['message' => 'Configura Resend para enviar guías por correo.'], 503);
        }
        Mail::to($data['email'])->send(new DeliveryGuidePdfMail($sale, $guide, Storage::disk('local')->get($guide->pdf_path)));

        return response()->json(['message' => 'Guía de remisión PDF enviada por correo.']);
    }

    public function signedPdf(DeliveryGuide $guide)
    {
        abort_unless($guide->pdf_path && Storage::disk('local')->exists($guide->pdf_path), 404);

        return response()->file(Storage::disk('local')->path($guide->pdf_path), [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="GRE-'.$guide->series.'-'.$guide->number.'.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
