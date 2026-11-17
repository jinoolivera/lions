<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\SunatSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BranchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Branch::query()->where('is_active', true)->with('warehouses:id,branch_id,code,name,is_active')->orderBy('id');
        if ($request->user()->role !== 'admin') {
            $query->whereIn('id', $request->user()->branches()->pluck('branches.id'));
        }

        return response()->json(['data' => $query->get()]);
    }

    public function update(Request $request, Branch $branch): JsonResponse
    {
        abort_unless($request->user()->role === 'admin', 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'address' => ['nullable', 'string', 'max:240'],
            'invoice_series' => ['sometimes', 'required', 'regex:/^F[A-Z0-9]{3}$/', Rule::unique('branches', 'invoice_series')->ignore($branch->id)],
            'receipt_series' => ['sometimes', 'required', 'regex:/^B[A-Z0-9]{3}$/', Rule::unique('branches', 'receipt_series')->ignore($branch->id)],
        ]);
        DB::transaction(function () use ($branch, $data): void {
            $locked = Branch::query()->lockForUpdate()->findOrFail($branch->id);
            foreach (['invoice', 'receipt'] as $kind) {
                $column = $kind.'_series';
                if (! isset($data[$column]) || $data[$column] === $locked->{$column}) continue;
                if (DB::table('sale_documents')->where('series', $data[$column])->exists()) {
                    throw ValidationException::withMessages([$column => 'La serie ya tiene comprobantes emitidos. Elige una serie nueva.']);
                }
                $locked->{'next_'.$kind.'_beta'} = 1;
                $locked->{'next_'.$kind.'_production'} = 1;
            }
            $locked->fill($data)->save();
        });

        return response()->json(['data' => $branch->fresh()]);
    }

    public function company(Request $request): JsonResponse
    {
        abort_unless($request->user()->role === 'admin', 403);
        $settings = SunatSetting::query()->find(1);

        return response()->json(['data' => [
            'ruc' => $settings?->ruc,
            'legal_name' => $settings?->legal_name,
            'trade_name' => $settings?->trade_name,
            'fiscal_address' => $settings?->fiscal_address,
            'ubigeo' => $settings?->ubigeo,
        ]]);
    }

    public function updateCompany(Request $request): JsonResponse
    {
        abort_unless($request->user()->role === 'admin', 403);
        $data = $request->validate([
            'ruc' => ['nullable', 'regex:/^\d{11}$/'],
            'legal_name' => ['nullable', 'string', 'max:180'],
            'trade_name' => ['nullable', 'string', 'max:180'],
            'fiscal_address' => ['nullable', 'string', 'max:240'],
            'ubigeo' => ['nullable', 'regex:/^\d{6}$/'],
        ]);
        $settings = SunatSetting::query()->firstOrNew(['id' => 1]);
        $settings->fill($data);
        $settings->save();

        return $this->company($request);
    }
}
