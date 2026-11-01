<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CashSession;
use App\Models\FinanceCategory;
use App\Models\FinancialTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class FinanceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'], 'type' => ['nullable', 'in:income,expense'],
        ]);
        $categories = FinanceCategory::query()->where('is_active', true)->orderBy('type')->orderBy('name')->get();
        $periodStartUtc = isset($filters['from']) ? CarbonImmutable::parse($filters['from'], config('app.timezone'))->startOfDay()->utc()->format('Y-m-d H:i:s') : null;
        $periodEndUtc = isset($filters['to']) ? CarbonImmutable::parse($filters['to'], config('app.timezone'))->addDay()->startOfDay()->utc()->format('Y-m-d H:i:s') : null;
        $transactions = FinancialTransaction::query()->with(['category', 'warehouse', 'user'])
            ->when($periodStartUtc, fn ($q) => $q->where('occurred_at', '>=', $periodStartUtc))
            ->when($periodEndUtc, fn ($q) => $q->where('occurred_at', '<', $periodEndUtc))
            ->when($filters['warehouse_id'] ?? null, fn ($q, $id) => $q->where('warehouse_id', $id))
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->latest('occurred_at')->limit(300)->get();
        return response()->json(['data' => ['categories' => $categories, 'transactions' => $transactions]]);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'], 'type' => ['required', 'in:income,expense'],
        ]);
        $category = FinanceCategory::query()->firstOrCreate(['name' => trim($data['name']), 'type' => $data['type']], ['is_active' => true]);
        if (! $category->is_active) $category->forceFill(['is_active' => true])->save();
        return response()->json(['data' => $category], $category->wasRecentlyCreated ? 201 : 200);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'operation_key' => ['required', 'string', 'max:100'], 'finance_category_id' => ['required', 'integer', 'exists:finance_categories,id'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'], 'cash_session_id' => ['nullable', 'integer', 'exists:cash_sessions,id'],
            'type' => ['required', 'in:income,expense'], 'method' => ['required', 'in:cash,card,transfer,other'],
            'amount_cents' => ['required', 'integer', 'min:1', 'max:999999999999'], 'reference' => ['nullable', 'string', 'max:120'],
            'description' => ['required', 'string', 'max:500'], 'occurred_at' => ['nullable', 'date'],
        ]);
        $category = FinanceCategory::query()->where('is_active', true)->findOrFail($data['finance_category_id']);
        if ($category->type !== $data['type']) throw ValidationException::withMessages(['finance_category_id' => 'La categoría no corresponde al tipo de movimiento.']);
        if ($data['method'] === 'cash' && empty($data['cash_session_id'])) throw ValidationException::withMessages(['cash_session_id' => 'Selecciona una caja abierta para registrar efectivo.']);
        $payload = [
            'finance_category_id' => (int) $data['finance_category_id'], 'warehouse_id' => isset($data['warehouse_id']) ? (int) $data['warehouse_id'] : null,
            'cash_session_id' => isset($data['cash_session_id']) ? (int) $data['cash_session_id'] : null, 'type' => $data['type'],
            'method' => $data['method'], 'amount_cents' => (int) $data['amount_cents'], 'reference' => $data['reference'] ?? null,
            'description' => trim($data['description']), 'occurred_at' => $data['occurred_at'] ?? now()->toISOString(),
        ];
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        $transaction = DB::transaction(function () use ($request, $data, $payload, $hash): FinancialTransaction {
            $existing = FinancialTransaction::query()->where('operation_key', $data['operation_key'])->first();
            if ($existing) {
                if (! hash_equals($existing->payload_hash, $hash)) throw new ConflictHttpException('La clave ya fue usada con otros datos.');
                return $existing;
            }
            $session = null;
            if ($payload['cash_session_id']) {
                $session = CashSession::query()->where('status', 'open')->lockForUpdate()->findOrFail($payload['cash_session_id']);
                if ($payload['warehouse_id'] && (int) $payload['warehouse_id'] !== (int) $session->warehouse_id) {
                    throw ValidationException::withMessages(['warehouse_id' => 'La caja pertenece a otro almacén.']);
                }
                $payload['warehouse_id'] ??= $session->warehouse_id;
            }
            if ($payload['method'] === 'cash' && $payload['type'] === 'expense' && $payload['amount_cents'] > (int) ($session?->expected_cents ?? 0)) {
                throw ValidationException::withMessages(['amount_cents' => 'El egreso supera el efectivo esperado disponible en caja.']);
            }
            $transaction = FinancialTransaction::query()->create($payload + [
                'user_id' => $request->user()->id, 'operation_key' => $data['operation_key'], 'payload_hash' => $hash,
            ]);
            if ($session && $payload['method'] === 'cash') {
                $session->increment('expected_cents', $payload['type'] === 'income' ? $payload['amount_cents'] : -$payload['amount_cents']);
            }
            return $transaction;
        }, 3);
        return response()->json(['data' => $transaction->load(['category', 'warehouse', 'user'])], 201);
    }
}
