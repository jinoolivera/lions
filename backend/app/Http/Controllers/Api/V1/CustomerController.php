<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CashSession;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\SalePayment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class CustomerController extends Controller
{
    public function index(): JsonResponse
    {
        $customers = Customer::query()->where('is_active', true)->orderBy('name')->limit(500)->get()->map(function (Customer $customer): array {
            $customer->setAttribute('outstanding_cents', (int) $customer->sales()->whereColumn('total_cents', '>', 'paid_cents')->selectRaw('SUM(total_cents - paid_cents) as aggregate')->value('aggregate'));
            return $customer->toArray();
        });
        return response()->json(['data' => $customers]);
    }

    public function store(Request $request): JsonResponse
    {
        $taxIdRules = ['nullable', 'string', 'max:20', 'unique:customers,tax_id', 'required_with:tax_id_type'];
        if ($request->input('tax_id_type') === 'dni') $taxIdRules[] = 'regex:/^\\d{8}$/';
        if ($request->input('tax_id_type') === 'ruc') $taxIdRules[] = 'regex:/^\\d{11}$/';
        $data = $request->validate([
            'name' => ['required', 'string', 'max:180'],
            'tax_id_type' => ['nullable', 'required_with:tax_id', 'in:dni,ruc,other'],
            'tax_id' => $taxIdRules,
            'email' => ['nullable', 'email', 'max:255'], 'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:2000'], 'credit_limit_cents' => ['required', 'integer', 'min:0', 'max:999999999999'],
        ]);
        $customer = Customer::query()->create($data + ['is_active' => true]);
        return response()->json(['data' => $customer->setAttribute('outstanding_cents', 0)], 201);
    }

    public function account(Customer $customer): JsonResponse
    {
        $sales = $customer->sales()->with(['payments', 'lines'])->latest('sold_at')->get()->map(function ($sale): array {
            $sale->setAttribute('balance_cents', max(0, $sale->total_cents - $sale->paid_cents));
            return $sale->toArray();
        });
        $advanceBalance = (int) SalePayment::query()->where('customer_id', $customer->id)->where('kind', 'advance')->sum('amount_cents')
            - (int) SalePayment::query()->where('customer_id', $customer->id)->where('kind', 'advance_application')->sum('amount_cents');
        return response()->json(['data' => [
            'customer' => $customer,
            'outstanding_cents' => (int) $customer->sales()->whereColumn('total_cents', '>', 'paid_cents')->selectRaw('SUM(total_cents - paid_cents) as aggregate')->value('aggregate'),
            'advance_balance_cents' => $advanceBalance,
            'advance_history' => SalePayment::query()->with('sale:id,sale_number')->where('customer_id', $customer->id)->whereIn('kind', ['advance', 'advance_application'])->latest()->limit(100)->get(),
            'sales' => $sales,
        ]]);
    }

    public function registerAdvance(Request $request, Customer $customer): JsonResponse
    {
        $data = $request->validate([
            'operation_key' => ['required', 'string', 'max:100'], 'cash_session_id' => ['required', 'integer', 'exists:cash_sessions,id'],
            'method' => ['required', 'in:cash,card,transfer,other'], 'amount_cents' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'reference' => ['nullable', 'string', 'max:120'],
        ]);
        $hash = hash('sha256', json_encode([$customer->id, $data['cash_session_id'], $data['method'], $data['amount_cents'], $data['reference'] ?? null], JSON_THROW_ON_ERROR));
        $payment = DB::transaction(function () use ($customer, $data, $hash, $request): SalePayment {
            $existing = SalePayment::query()->where('operation_key', $data['operation_key'])->first();
            if ($existing) {
                if (! hash_equals((string) $existing->payload_hash, $hash)) throw new ConflictHttpException('La clave de operación ya fue usada con otros datos.');
                return $existing;
            }
            $session = CashSession::query()->where('status', 'open')->lockForUpdate()->findOrFail($data['cash_session_id']);
            $payment = SalePayment::query()->create([
                'sale_id' => null, 'customer_id' => $customer->id, 'cash_session_id' => $session->id, 'user_id' => $request->user()->id,
                'kind' => 'advance', 'method' => $data['method'], 'amount_cents' => $data['amount_cents'], 'reference' => $data['reference'] ?? null,
                'operation_key' => $data['operation_key'], 'payload_hash' => $hash,
            ]);
            if ($data['method'] === 'cash') $session->increment('expected_cents', $data['amount_cents']);
            return $payment;
        }, 3);
        return response()->json(['data' => $payment->load('sale')], 201);
    }

    public function applyAdvance(Request $request, Customer $customer): JsonResponse
    {
        $data = $request->validate([
            'operation_key' => ['required', 'string', 'max:100'], 'sale_id' => ['required', 'integer', 'exists:sales,id'],
            'amount_cents' => ['required', 'integer', 'min:1', 'max:999999999999'],
        ]);
        $hash = hash('sha256', json_encode([$customer->id, $data['sale_id'], $data['amount_cents']], JSON_THROW_ON_ERROR));
        $sale = DB::transaction(function () use ($customer, $data, $hash, $request): Sale {
            $existing = SalePayment::query()->where('operation_key', $data['operation_key'])->first();
            if ($existing) {
                if (! hash_equals((string) $existing->payload_hash, $hash)) throw new ConflictHttpException('La clave de operación ya fue usada con otros datos.');
                return Sale::query()->with(['payments', 'customer'])->findOrFail($data['sale_id']);
            }
            $lockedCustomer = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            $lockedSale = Sale::query()->where('customer_id', $lockedCustomer->id)->lockForUpdate()->findOrFail($data['sale_id']);
            $available = (int) SalePayment::query()->where('customer_id', $lockedCustomer->id)->where('kind', 'advance')->sum('amount_cents')
                - (int) SalePayment::query()->where('customer_id', $lockedCustomer->id)->where('kind', 'advance_application')->sum('amount_cents');
            $debt = max(0, $lockedSale->total_cents - $lockedSale->paid_cents);
            if ($data['amount_cents'] > $available) throw ValidationException::withMessages(['amount_cents' => 'El importe supera el saldo a favor disponible.']);
            if ($data['amount_cents'] > $debt) throw ValidationException::withMessages(['amount_cents' => 'El importe supera el saldo de la venta.']);
            SalePayment::query()->create([
                'sale_id' => $lockedSale->id, 'customer_id' => $lockedCustomer->id, 'cash_session_id' => null, 'user_id' => $request->user()->id,
                'kind' => 'advance_application', 'method' => 'advance', 'amount_cents' => $data['amount_cents'],
                'reference' => 'Aplicación de saldo a favor', 'operation_key' => $data['operation_key'], 'payload_hash' => $hash,
            ]);
            $lockedSale->increment('paid_cents', $data['amount_cents']);
            if ($data['amount_cents'] >= $debt) $lockedSale->forceFill(['status' => 'completed'])->save();
            return $lockedSale->fresh()->load(['payments', 'customer']);
        }, 3);
        return response()->json(['data' => $sale]);
    }
}
