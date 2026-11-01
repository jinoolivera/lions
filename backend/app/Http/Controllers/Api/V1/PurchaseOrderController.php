<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductPresentation;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Services\PurchaseReceptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

class PurchaseOrderController extends Controller
{
    public function index(): JsonResponse
    {
        $orders = PurchaseOrder::query()->with(['supplier:id,name,tax_id', 'invoices.payments'])->withCount(['lines', 'receipts', 'invoices'])
            ->latest('id')->limit(100)->get();

        return response()->json(['data' => $orders]);
    }

    public function show(PurchaseOrder $purchaseOrder): JsonResponse
    {
        return response()->json(['data' => $purchaseOrder->load(['supplier', 'lines', 'receipts.lines', 'invoices.payments'])]);
    }

    public function direct(Request $request, PurchaseReceptionService $receptions): JsonResponse
    {
        $request->validate([
            'receipt_key' => ['required', 'string', 'max:64'], 'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'warehouse_location_id' => ['sometimes', 'integer', 'exists:warehouse_locations,id'],
            'supplier_document_reference' => ['nullable', 'string', 'max:120'],
        ]);
        return DB::transaction(function () use ($request, $receptions): JsonResponse {
            $orderResponse = $this->store($request);
            $orderId = $orderResponse->getData(true)['data']['id'];
            $order = PurchaseOrder::query()->with('lines')->findOrFail($orderId);
            $request->merge(['lines' => $order->lines->map(fn ($line): array => [
                'purchase_order_line_id' => $line->id, 'quantity' => $line->ordered_quantity,
            ])->all(), 'notes' => $request->input('notes')]);
            $receiptResponse = $this->receive($request, $order, $receptions);
            return response()->json(['data' => $order->fresh()->load(['supplier', 'lines', 'receipts.lines']), 'receipt' => $receiptResponse->getData(true)['data']], 201);
        }, 3);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'operation_key' => ['required', 'string', 'max:100'],
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'currency_code' => ['sometimes', Rule::in(['PEN', 'USD', 'EUR'])],
            'exchange_rate' => ['sometimes', 'numeric', 'gt:0', 'regex:/^\d{1,9}(?:\.\d{1,8})?$/'],
            'exchange_rate_date' => ['required_if:currency_code,USD,EUR', 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'landed_cost_cents' => ['sometimes', 'integer', 'min:0', 'max:999999999999'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.presentation_id' => ['required', 'integer', 'exists:product_presentations,id'],
            'lines.*.quantity' => ['required', 'regex:/^\d{1,12}(?:\.\d{1,6})?$/'],
            'lines.*.unit_cost_cents' => ['required', 'integer', 'min:0', 'max:999999999999'],
        ]);
        $currencyCode = strtoupper($data['currency_code'] ?? 'PEN');
        $exchangeRate = BigDecimal::of((string) ($data['exchange_rate'] ?? '1'))->toScale(8, RoundingMode::UNNECESSARY);
        if ($currencyCode === 'PEN' && $exchangeRate->compareTo(BigDecimal::of('1')) !== 0) {
            throw ValidationException::withMessages(['exchange_rate' => 'Para compras en PEN, el tipo de cambio debe ser 1.']);
        }
        $exchangeRateDate = $data['exchange_rate_date'] ?? now()->toDateString();
        $toPenCents = static fn (int $amount): int => BigDecimal::of($amount)->multipliedBy($exchangeRate)
            ->toScale(0, RoundingMode::HALF_UP)->toInt();
        $documentLandedCostCents = (int) ($data['landed_cost_cents'] ?? 0);
        $landedCostPenCents = $toPenCents($documentLandedCostCents);
        if ($landedCostPenCents > 999999999999) {
            throw ValidationException::withMessages(['landed_cost_cents' => 'El gasto convertido a soles supera el máximo permitido.']);
        }
        foreach ($data['lines'] as $index => $lineData) {
            if ($toPenCents((int) $lineData['unit_cost_cents']) > 999999999999) {
                throw ValidationException::withMessages(["lines.{$index}.unit_cost_cents" => 'El costo convertido a soles supera el máximo permitido.']);
            }
        }
        $hashPayload = ['supplier' => $data['supplier_id'], 'notes' => $data['notes'] ?? null, 'lines' => $data['lines']];
        if ((int) ($data['landed_cost_cents'] ?? 0) > 0) $hashPayload['landed_cost_cents'] = (int) $data['landed_cost_cents'];
        if ($currencyCode !== 'PEN') {
            $hashPayload['currency_code'] = $currencyCode;
            $hashPayload['exchange_rate'] = (string) $exchangeRate;
            $hashPayload['exchange_rate_date'] = $exchangeRateDate;
        }
        $payloadHash = hash('sha256', json_encode($hashPayload, JSON_THROW_ON_ERROR));
        $existing = PurchaseOrder::query()->where('operation_key', $data['operation_key'])->first();
        if ($existing) {
            abort_unless(hash_equals($existing->payload_hash, $payloadHash), 409, 'La clave ya fue usada con otra orden.');

            return response()->json(['data' => $existing->load(['supplier', 'lines'])]);
        }
        $supplier = Supplier::query()->where('is_active', true)->findOrFail($data['supplier_id']);

        $order = DB::transaction(function () use ($request, $data, $supplier, $payloadHash, $currencyCode, $exchangeRate, $exchangeRateDate, $documentLandedCostCents, $landedCostPenCents, $toPenCents): PurchaseOrder {
            $existing = PurchaseOrder::query()->where('operation_key', $data['operation_key'])->first();
            if ($existing) {
                abort_unless(hash_equals($existing->payload_hash, $payloadHash), 409, 'La clave ya fue usada con otra orden.');
                return $existing;
            }
            $order = PurchaseOrder::query()->create([
                'supplier_id' => $supplier->getKey(), 'user_id' => $request->user()->id,
                'order_number' => 'PO-'.now()->format('ymd').'-'.Str::upper(Str::random(8)),
                'operation_key' => $data['operation_key'], 'payload_hash' => $payloadHash,
                'status' => 'ordered', 'notes' => $data['notes'] ?? null,
                'currency_code' => $currencyCode, 'exchange_rate' => (string) $exchangeRate,
                'exchange_rate_date' => $exchangeRateDate, 'document_landed_cost_cents' => $documentLandedCostCents,
                'landed_cost_cents' => $landedCostPenCents, 'landed_cost_allocated_cents' => 0,
                'ordered_at' => now(),
            ]);
            foreach ($data['lines'] as $lineData) {
                $product = Product::query()->where('is_active', true)->findOrFail($lineData['product_id']);
                $presentation = ProductPresentation::query()->where('product_id', $product->id)
                    ->where('is_active', true)->findOrFail($lineData['presentation_id']);
                $quantity = \Brick\Math\BigDecimal::of($lineData['quantity'])->toScale(6, \Brick\Math\RoundingMode::UNNECESSARY);
                if (! $quantity->isPositive()) {
                    abort(422, 'La cantidad de compra debe ser mayor que cero.');
                }
                PurchaseOrderLine::query()->create([
                    'purchase_order_id' => $order->getKey(), 'product_id' => $product->getKey(),
                    'product_presentation_id' => $presentation->getKey(),
                    'product_name_snapshot' => $product->name, 'presentation_name_snapshot' => $presentation->name,
                    'factor_to_base' => $presentation->factor_to_base, 'ordered_quantity' => (string) $quantity,
                    'received_quantity' => '0.000000', 'document_unit_cost_cents' => (int) $lineData['unit_cost_cents'],
                    'unit_cost_cents' => $toPenCents((int) $lineData['unit_cost_cents']),
                ]);
            }

            return $order;
        });

        return response()->json(['data' => $order->load(['supplier', 'lines'])], 201);
    }

    public function receive(Request $request, PurchaseOrder $purchaseOrder, PurchaseReceptionService $receptions): JsonResponse
    {
        $data = $request->validate([
            'receipt_key' => ['required', 'string', 'max:64'],
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'warehouse_location_id' => ['sometimes', 'integer', 'exists:warehouse_locations,id'],
            'supplier_document_reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.purchase_order_line_id' => ['required', 'integer', 'distinct', 'exists:purchase_order_lines,id'],
            'lines.*.quantity' => ['required', 'regex:/^\d{1,12}(?:\.\d{1,6})?$/'],
        ]);

        $receipt = $receptions->receive($purchaseOrder, $data, $request->user()->id);

        return response()->json(['data' => $receipt->load('lines'), 'order' => $purchaseOrder->fresh()->load('lines')], 201);
    }

    public function invoice(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $data = $request->validate([
            'document_type' => ['required', Rule::in(['invoice', 'receipt', 'other'])],
            'series' => ['required', 'string', 'max:20'], 'number' => ['required', 'string', 'max:32'],
            'issue_date' => ['required', 'date'], 'due_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'currency_code' => ['sometimes', Rule::in(['PEN', 'USD', 'EUR'])],
            'document_total_cents' => ['sometimes', 'integer', 'min:1', 'max:999999999999'],
            'exchange_rate' => ['sometimes', 'numeric', 'gt:0', 'regex:/^\d{1,9}(?:\.\d{1,8})?$/'],
            'total_cents' => ['sometimes', 'integer', 'min:1', 'max:999999999999'],
            'taxable_base_cents' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'igv_cents' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'exempt_amount_cents' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'unaffected_amount_cents' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'other_taxes_cents' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'reconciliation_note' => ['nullable', 'string', 'max:500'],
        ]);
        if (! isset($data['document_total_cents']) && ! isset($data['total_cents'])) {
            throw ValidationException::withMessages(['document_total_cents' => 'Ingresa el total del documento del proveedor.']);
        }
        $currencyCode = strtoupper($data['currency_code'] ?? 'PEN');
        $documentTotalCents = (int) ($data['document_total_cents'] ?? $data['total_cents']);
        $exchangeRate = BigDecimal::of((string) ($data['exchange_rate'] ?? '1'))->toScale(8, RoundingMode::UNNECESSARY);
        if ($currencyCode === 'PEN' && $exchangeRate->compareTo(BigDecimal::of('1')) !== 0) {
            throw ValidationException::withMessages(['exchange_rate' => 'Para documentos en PEN, el tipo de cambio debe ser 1.']);
        }
        $totalPenCents = BigDecimal::of($documentTotalCents)->multipliedBy($exchangeRate)
            ->toScale(0, RoundingMode::HALF_UP)->toInt();
        if ($totalPenCents < 1 || $totalPenCents > 999999999999) {
            throw ValidationException::withMessages(['document_total_cents' => 'El importe convertido a soles está fuera del rango permitido.']);
        }
        $taxKeys = ['taxable_base_cents', 'igv_cents', 'exempt_amount_cents', 'unaffected_amount_cents', 'other_taxes_cents'];
        $taxAnyProvided = collect($taxKeys)->contains(fn (string $key): bool => array_key_exists($key, $data) && $data[$key] !== null);
        $taxAllProvided = collect($taxKeys)->every(fn (string $key): bool => array_key_exists($key, $data) && $data[$key] !== null);
        if ($taxAnyProvided && ! $taxAllProvided) {
            throw ValidationException::withMessages(['taxable_base_cents' => 'Completa todos los importes del desglose tributario o déjalos todos vacíos.']);
        }
        $taxBreakdown = null;
        if ($taxAllProvided) {
            $taxBreakdown = collect($taxKeys)->mapWithKeys(fn (string $key): array => [$key => (int) $data[$key]])->all();
            if (array_sum($taxBreakdown) !== $documentTotalCents) {
                throw ValidationException::withMessages(['taxable_base_cents' => 'La base, IGV, exonerado, inafecto y otros tributos deben sumar el total del documento.']);
            }
        }
        $data['series'] = strtoupper(trim($data['series']));
        $data['number'] = trim($data['number']);

        $invoice = DB::transaction(function () use ($request, $purchaseOrder, $data, $currencyCode, $documentTotalCents, $exchangeRate, $totalPenCents, $taxBreakdown): SupplierInvoice {
            $order = PurchaseOrder::query()->whereKey($purchaseOrder->getKey())->lockForUpdate()->firstOrFail();
            abort_if($order->status === 'cancelled', 409, 'No se puede vincular una factura a una orden cancelada.');
            $merchandiseCents = $order->lines()->get()->sum(fn ($line): int => BigDecimal::of($line->ordered_quantity)
                ->multipliedBy($line->unit_cost_cents)->toScale(0, RoundingMode::HALF_UP)->toInt());
            $orderAmountCents = $merchandiseCents + (int) $order->landed_cost_cents;
            $varianceCents = $totalPenCents - $orderAmountCents;
            if ($varianceCents !== 0 && blank($data['reconciliation_note'] ?? null)) {
                throw ValidationException::withMessages(['reconciliation_note' => 'Registra el motivo de la diferencia entre la factura y la orden, incluidos sus gastos asociados.']);
            }

            return SupplierInvoice::query()->create([
                'supplier_id' => $order->supplier_id, 'purchase_order_id' => $order->getKey(),
                'user_id' => $request->user()->id, 'document_type' => $data['document_type'],
                'series' => $data['series'], 'number' => $data['number'], 'issue_date' => $data['issue_date'],
                'due_date' => $data['due_date'] ?? null, 'currency_code' => $currencyCode,
                'document_total_cents' => $documentTotalCents, 'exchange_rate' => (string) $exchangeRate,
                'taxable_base_cents' => $taxBreakdown['taxable_base_cents'] ?? null,
                'igv_cents' => $taxBreakdown['igv_cents'] ?? null,
                'exempt_amount_cents' => $taxBreakdown['exempt_amount_cents'] ?? null,
                'unaffected_amount_cents' => $taxBreakdown['unaffected_amount_cents'] ?? null,
                'other_taxes_cents' => $taxBreakdown['other_taxes_cents'] ?? null,
                'tax_breakdown_status' => $taxBreakdown === null ? 'not_provided' : 'provided',
                'total_cents' => $totalPenCents, 'order_amount_cents' => $orderAmountCents,
                'variance_cents' => $varianceCents, 'reconciliation_status' => $varianceCents === 0 ? 'matched' : 'difference_noted',
                'reconciliation_note' => $data['reconciliation_note'] ?? null, 'paid_cents' => 0, 'status' => 'open',
            ]);
        });

        return response()->json(['data' => $invoice], 201);
    }
}
