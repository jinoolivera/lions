<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\SaleDocument;
use App\Models\SaleDocumentAdjustment;
use App\Models\SunatSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class SaleDocumentWorkflowController extends Controller
{
    public function updateBilling(Request $request, Sale $sale): JsonResponse
    {
        $data = $request->validate([
            'requested_document_type' => ['required', 'in:invoice,receipt'],
            'customer_id' => ['present', 'nullable', 'integer', 'exists:customers,id'],
        ]);

        $updated = DB::transaction(function () use ($sale, $data): Sale {
            $sale = Sale::query()->lockForUpdate()->findOrFail($sale->id);
            if (in_array($sale->status, ['cancelled', 'returned'], true) || $sale->documents()->exists()) {
                throw new ConflictHttpException('Los datos de facturación no se pueden cambiar después de emitir o anular la venta.');
            }
            $customer = $data['customer_id'] ? Customer::query()->where('is_active', true)->findOrFail($data['customer_id']) : null;
            if ((int) ($sale->customer_id ?? 0) !== (int) ($customer?->id ?? 0) && $sale->paid_cents < $sale->total_cents) {
                throw ValidationException::withMessages(['customer_id' => 'No se puede cambiar el cliente de una venta con saldo a crédito.']);
            }
            if ($data['requested_document_type'] === 'invoice' && ($customer?->tax_id_type !== 'ruc' || ! preg_match('/^\d{11}$/', (string) $customer?->tax_id))) {
                throw ValidationException::withMessages(['customer_id' => 'La factura requiere un cliente registrado con RUC de 11 dígitos.']);
            }
            if ($data['requested_document_type'] === 'receipt' && $sale->total_cents > 70000 && (! $customer?->tax_id || ! $customer?->name)) {
                throw ValidationException::withMessages(['customer_id' => 'La boleta mayor a S/ 700 requiere un cliente identificado.']);
            }

            $sale->forceFill([
                'customer_id' => $customer?->id,
                'customer_name' => $customer?->name,
                'requested_document_type' => $data['requested_document_type'],
            ])->save();

            return $sale->load(['customer', 'documents']);
        }, 3);

        return response()->json(['data' => $updated]);
    }

    public function registerAdjustment(Request $request, Sale $sale, SaleDocument $document): JsonResponse
    {
        abort_unless((int) $document->sale_id === (int) $sale->id, 404);
        $settings = SunatSetting::query()->whereKey(1)->first();
        abort_unless($settings?->billing_mode === 'manual' && $document->environment === 'external', 409,
            'Las notas externas se registran solo para comprobantes emitidos fuera de Lions, en modo manual.');
        $data = $request->validate([
            'adjustment_type' => ['required', 'in:credit_note,debit_note'],
            'series' => ['required', 'regex:/^[FB][A-Z0-9]{3}$/'],
            'number' => ['required', 'regex:/^[0-9]{1,8}$/'],
            'issue_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'total_cents' => ['required', 'integer', 'min:0'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'issued_via' => ['required', 'in:see_sol,pse'],
            'pdf' => ['nullable', 'file', 'mimes:pdf', 'max:5120'],
        ]);
        if ($data['issue_date'] < $document->issue_date->toDateString()) {
            throw ValidationException::withMessages(['issue_date' => 'La nota no puede ser anterior al comprobante original.']);
        }
        if ($data['series'][0] !== ($document->document_type === 'invoice' ? 'F' : 'B')) {
            throw ValidationException::withMessages(['series' => 'La serie de la nota debe corresponder al tipo del comprobante original.']);
        }
        if (SaleDocumentAdjustment::query()->where('series', $data['series'])->where('number', $data['number'])->exists()) {
            throw ValidationException::withMessages(['number' => 'Esta nota ya está registrada.']);
        }
        if ($data['adjustment_type'] === 'credit_note') {
            $credited = (int) $document->adjustments()->where('adjustment_type', 'credit_note')->sum('total_cents');
            if ($credited + $data['total_cents'] > $document->total_cents) {
                throw ValidationException::withMessages(['total_cents' => 'Las notas de crédito no pueden superar el importe del comprobante original.']);
            }
        }

        $pdf = $request->file('pdf');
        $pdfPath = $pdf ? $pdf->store('cpe/adjustments', 'local') : null;
        try {
            $adjustment = $document->adjustments()->create([
                'user_id' => $request->user()->id,
                'adjustment_type' => $data['adjustment_type'],
                'series' => $data['series'], 'number' => $data['number'],
                'issue_date' => $data['issue_date'], 'total_cents' => $data['total_cents'],
                'reason' => trim($data['reason']), 'issued_via' => $data['issued_via'],
                'status' => 'registered_external', 'pdf_path' => $pdfPath,
            ]);
        } catch (\Throwable $exception) {
            if ($pdfPath) {
                Storage::disk('local')->delete($pdfPath);
            }
            throw $exception;
        }

        return response()->json(['data' => $adjustment->load('user')], 201);
    }

    public function attachPdf(Request $request, Sale $sale, SaleDocument $document): JsonResponse
    {
        abort_unless((int) $document->sale_id === (int) $sale->id, 404);
        abort_unless($document->environment === 'external', 409, 'Solo se puede adjuntar una representación externa.');
        $request->validate(['pdf' => ['required', 'file', 'mimes:pdf', 'max:5120']]);
        $path = $request->file('pdf')->store('cpe/external', 'local');
        $oldPath = $document->pdf_path;
        $document->forceFill(['pdf_path' => $path])->save();
        if ($oldPath) {
            Storage::disk('local')->delete($oldPath);
        }

        return response()->json(['data' => $document->fresh()]);
    }

    public function downloadPdf(Sale $sale, SaleDocument $document)
    {
        abort_unless((int) $document->sale_id === (int) $sale->id && $document->pdf_path, 404);

        return Storage::disk('local')->download($document->pdf_path, $document->series.'-'.$document->number.'.pdf', [
            'Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function downloadAdjustmentPdf(Sale $sale, SaleDocument $document, SaleDocumentAdjustment $adjustment)
    {
        abort_unless((int) $document->sale_id === (int) $sale->id && (int) $adjustment->sale_document_id === (int) $document->id && $adjustment->pdf_path, 404);

        return Storage::disk('local')->download($adjustment->pdf_path, $adjustment->series.'-'.$adjustment->number.'.pdf', [
            'Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
