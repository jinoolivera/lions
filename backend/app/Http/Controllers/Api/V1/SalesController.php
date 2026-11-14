<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CashSession;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\SunatSetting;
use App\Services\SaleReturnService;
use App\Services\SalesService;
use App\Services\SunatCpeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class SalesController extends Controller
{
    public function emailTicket(Request $request, Sale $sale): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:254']]);
        if (config('mail.default') !== 'resend' || ! config('services.resend.key')) {
            return response()->json(['message' => 'Configura Resend para enviar tickets por correo.'], 503);
        }
        $sale->load(['lines', 'payments']);
        Mail::send('emails.sale-ticket', ['sale' => $sale], function ($message) use ($data, $sale) {
            $message->to($data['email'])->subject('Ticket de venta '.$sale->sale_number.' · U.LIONS');
        });
        return response()->json(['message' => 'Ticket enviado por correo.']);
    }

    public function index(Request $request): JsonResponse
    {
        $warehouseId = $request->integer('warehouse_id');

        return response()->json(['data' => Sale::query()->with(['lines', 'payments', 'cashSession', 'customer', 'returns.lines', 'documents.user'])->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))->latest('sold_at')->limit(100)->get()]);
    }

    public function registerExternalDocument(Request $request, Sale $sale): JsonResponse
    {
        abort_if(in_array($sale->status, ['cancelled', 'returned'], true), 409, 'No se puede vincular un comprobante a una venta anulada o devuelta.');
        $settings = SunatSetting::query()->whereKey(1)->first();
        abort_unless($settings?->billing_mode === 'manual', 409, 'El modo de facturación no está configurado para registro manual externo.');

        $data = $request->validate([
            'document_type' => ['required', 'in:invoice,receipt'],
            'series' => ['required', 'regex:/^(F|B)[A-Z0-9]{3}$/'],
            'number' => ['required', 'regex:/^[0-9]{1,8}$/'],
            'issue_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'total_cents' => ['required', 'integer', 'min:1'],
            'issued_via' => ['required', 'in:see_sol,pse'],
        ]);
        $seriesPrefix = $data['document_type'] === 'invoice' ? 'F' : 'B';
        if (! str_starts_with($data['series'], $seriesPrefix)) {
            throw ValidationException::withMessages(['series' => $data['document_type'] === 'invoice' ? 'La serie de factura debe iniciar con F.' : 'La serie de boleta debe iniciar con B.']);
        }
        if ((int) $data['total_cents'] !== (int) $sale->total_cents) {
            throw ValidationException::withMessages(['total_cents' => 'El total registrado debe coincidir con el total de la venta.']);
        }
        if ($sale->documents()->exists()) {
            throw ValidationException::withMessages(['document_type' => 'Esta venta ya tiene un comprobante vinculado.']);
        }
        if (\App\Models\SaleDocument::query()->where('series', $data['series'])->where('number', $data['number'])->exists()) {
            throw ValidationException::withMessages(['number' => 'Ese comprobante ya fue registrado en otra venta.']);
        }
        if ($data['document_type'] === 'invoice' && ($sale->customer?->tax_id_type !== 'ruc' || ! preg_match('/^\d{11}$/', (string) $sale->customer?->tax_id))) {
            throw ValidationException::withMessages(['document_type' => 'Para registrar factura, la venta debe tener cliente registrado con RUC de 11 dígitos.']);
        }

        $document = $sale->documents()->create($data + [
            'user_id' => $request->user()->id,
            'status' => 'registered_external',
        ]);

        return response()->json(['data' => $document->load('user')], 201);
    }

    public function issueSunatDocument(Request $request, Sale $sale, SunatCpeService $service): JsonResponse
    {
        $data = $request->validate(['document_type' => ['required', 'in:invoice,receipt']]);

        return response()->json(['data' => $service->issueSale($sale, $data['document_type'], $request->user())], 201);
    }

    public function downloadSunatDocument(Request $request, SaleDocument $document, string $kind, SunatCpeService $service)
    {
        abort_unless(in_array($kind, ['xml', 'cdr'], true), 404);
        $contents = $service->download($document, $kind);
        $name = $document->series.'-'.$document->number.($kind === 'xml' ? '.xml' : '-CDR.xml');

        return response($contents, 200, ['Content-Type' => 'application/xml; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="'.$name.'"']);
    }

    public function create(Request $request, SalesService $service): JsonResponse
    {
        $data = $request->validate([
            'operation_key' => ['required', 'string', 'max:100'], 'cash_session_id' => ['required', 'integer', 'exists:cash_sessions,id'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'], 'customer_name' => ['nullable', 'string', 'max:180'], 'lines' => ['required', 'array', 'min:1', 'max:100'],
            'warehouse_location_id' => ['sometimes', 'integer', 'exists:warehouse_locations,id'],
            'due_date' => ['nullable', 'date', 'after_or_equal:today'],
            'price_list' => ['sometimes', 'in:retail,wholesale'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'], 'lines.*.presentation_id' => ['required', 'integer', 'exists:product_presentations,id'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'lines.*.unit_price_cents' => ['sometimes', 'integer', 'min:1', 'max:999999999999'],
            'lines.*.discount_cents' => ['sometimes', 'integer', 'min:0', 'max:999999999999'],
            'payments' => ['present', 'array', 'max:10'], 'payments.*.method' => ['required', 'in:cash,card,transfer,other'],
            'payments.*.amount_cents' => ['required', 'integer', 'min:1', 'max:999999999999'], 'payments.*.reference' => ['nullable', 'string', 'max:120'],
        ]);

        return response()->json(['data' => $service->create($data, (int) $request->user()->id)], 201);
    }

    public function collectPayment(Request $request, Sale $sale): JsonResponse
    {
        $data = $request->validate([
            'operation_key' => ['required', 'string', 'max:100'], 'cash_session_id' => ['required', 'integer', 'exists:cash_sessions,id'],
            'method' => ['required', 'in:cash,card,transfer,other'], 'amount_cents' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'reference' => ['nullable', 'string', 'max:120'],
        ]);
        $payloadHash = hash('sha256', implode('|', [$sale->id, $data['cash_session_id'], $data['method'], $data['amount_cents'], $data['reference'] ?? '']));
        $updated = DB::transaction(function () use ($sale, $data, $payloadHash, $request): Sale {
            $existing = SalePayment::query()->where('operation_key', $data['operation_key'])->first();
            if ($existing) {
                if (! hash_equals((string) $existing->payload_hash, $payloadHash)) {
                    throw new ConflictHttpException('La clave de pago ya fue usada con datos distintos.');
                }

                return $sale->fresh()->load(['payments', 'customer']);
            }
            $lockedSale = Sale::query()->lockForUpdate()->findOrFail($sale->id);
            $session = CashSession::query()->where('status', 'open')->lockForUpdate()->findOrFail($data['cash_session_id']);
            if ($session->warehouse_id !== $lockedSale->warehouse_id) {
                throw ValidationException::withMessages(['cash_session_id' => 'La caja debe pertenecer al mismo almacén de la venta.']);
            }
            $balance = $lockedSale->total_cents - $lockedSale->paid_cents;
            if ($data['amount_cents'] > $balance) {
                throw ValidationException::withMessages(['amount_cents' => 'El pago excede el saldo pendiente.']);
            }
            SalePayment::query()->create([
                'sale_id' => $lockedSale->id, 'cash_session_id' => $session->id, 'user_id' => $request->user()->id,
                'kind' => 'collection', 'method' => $data['method'], 'amount_cents' => $data['amount_cents'],
                'reference' => $data['reference'] ?? null, 'operation_key' => $data['operation_key'], 'payload_hash' => $payloadHash,
            ]);
            $lockedSale->increment('paid_cents', $data['amount_cents']);
            if ($lockedSale->paid_cents + $data['amount_cents'] >= $lockedSale->total_cents) {
                $lockedSale->forceFill(['status' => 'completed'])->save();
            }
            if ($data['method'] === 'cash') {
                $session->increment('expected_cents', $data['amount_cents']);
            }

            return $lockedSale->fresh()->load(['payments', 'customer']);
        }, 3);

        return response()->json(['data' => $updated]);
    }

    public function returnSale(Request $request, Sale $sale, SaleReturnService $service): JsonResponse
    {
        $data = $request->validate([
            'operation_key' => ['required', 'string', 'max:100'], 'reason' => ['required', 'string', 'min:3', 'max:500'],
            'refund_method' => ['nullable', 'in:cash,card,transfer,other'], 'cash_session_id' => ['nullable', 'integer', 'exists:cash_sessions,id'],
            'lines' => ['required', 'array', 'min:1', 'max:100'], 'lines.*.sale_line_id' => ['required', 'integer', 'distinct', 'exists:sale_lines,id'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
        ]);

        return response()->json(['data' => $service->create($sale, $data, (int) $request->user()->id)->load(['lines.saleLine', 'sale'])], 201);
    }

    public function cancelSale(Request $request, Sale $sale, SaleReturnService $service): JsonResponse
    {
        $data = $request->validate([
            'operation_key' => ['required', 'string', 'max:100'], 'reason' => ['required', 'string', 'min:3', 'max:500'],
            'refund_method' => ['nullable', 'in:cash,card,transfer,other'], 'cash_session_id' => ['nullable', 'integer', 'exists:cash_sessions,id'],
        ]);

        return response()->json(['data' => $service->create($sale, $data, (int) $request->user()->id, true)->load(['lines.saleLine', 'sale'])], 201);
    }

    public function sessions(Request $request): JsonResponse
    {
        return response()->json(['data' => CashSession::query()->with('warehouse')->where('status', 'open')->latest('opened_at')->get()]);
    }

    public function openSession(Request $request): JsonResponse
    {
        $data = $request->validate(['warehouse_id' => ['required', 'integer', 'exists:warehouses,id'], 'opening_cents' => ['required', 'integer', 'min:0', 'max:999999999999']]);
        $session = DB::transaction(function () use ($data, $request): CashSession {
            if (CashSession::query()->where('warehouse_id', $data['warehouse_id'])->where('status', 'open')->lockForUpdate()->exists()) {
                throw new ConflictHttpException('Ya existe una caja abierta para este almacén.');
            }

            return CashSession::query()->create(['warehouse_id' => $data['warehouse_id'], 'user_id' => $request->user()->id, 'opening_cents' => $data['opening_cents'], 'expected_cents' => $data['opening_cents'], 'status' => 'open', 'opened_at' => now()]);
        }, 3);

        return response()->json(['data' => $session->load('warehouse')], 201);
    }

    public function closeSession(Request $request, CashSession $cashSession): JsonResponse
    {
        $data = $request->validate(['counted_cents' => ['required', 'integer', 'min:0', 'max:999999999999']]);

        return DB::transaction(function () use ($cashSession, $data): JsonResponse {
            $session = CashSession::query()->lockForUpdate()->findOrFail($cashSession->id);
            if ($session->status !== 'open') {
                throw new ConflictHttpException('La caja ya está cerrada.');
            }
            $session->forceFill(['counted_cents' => $data['counted_cents'], 'status' => 'closed', 'closed_at' => now()])->save();

            return response()->json(['data' => $session]);
        }, 3);
    }
}
