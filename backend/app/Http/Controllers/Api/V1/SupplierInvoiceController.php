<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoicePayment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Illuminate\Validation\ValidationException;

class SupplierInvoiceController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => SupplierInvoice::query()->with(['supplier:id,name,tax_id', 'payments'])->latest('issue_date')->limit(200)->get()]);
    }

    public function pay(Request $request, SupplierInvoice $supplierInvoice): JsonResponse
    {
        $data = $request->validate([
            'operation_key' => ['required', 'string', 'max:100'], 'method' => ['required', 'in:cash,card,transfer,other'],
            'amount_cents' => ['required', 'integer', 'min:1', 'max:999999999999'], 'reference' => ['nullable', 'string', 'max:120'],
        ]);
        $payloadHash = hash('sha256', implode('|', [$supplierInvoice->id, $data['method'], $data['amount_cents'], $data['reference'] ?? '']));
        $invoice = DB::transaction(function () use ($request, $supplierInvoice, $data, $payloadHash): SupplierInvoice {
            $existing = SupplierInvoicePayment::query()->where('operation_key', $data['operation_key'])->first();
            if ($existing) {
                if (! hash_equals($existing->payload_hash, $payloadHash)) throw new ConflictHttpException('La clave de pago ya fue usada con otros datos.');
                return $supplierInvoice->fresh()->load('payments');
            }
            $invoice = SupplierInvoice::query()->lockForUpdate()->findOrFail($supplierInvoice->id);
            $balance = $invoice->total_cents - $invoice->paid_cents;
            if ($data['amount_cents'] > $balance) throw ValidationException::withMessages(['amount_cents' => 'El pago excede el saldo pendiente de la factura.']);
            SupplierInvoicePayment::query()->create([
                'supplier_invoice_id' => $invoice->id, 'user_id' => $request->user()->id,
                'operation_key' => $data['operation_key'], 'payload_hash' => $payloadHash,
                'method' => $data['method'], 'amount_cents' => $data['amount_cents'],
                'reference' => $data['reference'] ?? null, 'paid_at' => now(),
            ]);
            $paid = $invoice->paid_cents + $data['amount_cents'];
            $invoice->forceFill(['paid_cents' => $paid, 'status' => $paid >= $invoice->total_cents ? 'paid' : 'partially_paid'])->save();
            return $invoice->fresh()->load('payments');
        }, 3);
        return response()->json(['data' => $invoice]);
    }
}
