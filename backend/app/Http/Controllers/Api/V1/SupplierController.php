<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => Supplier::query()->where('is_active', true)->orderBy('name')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->merge(['tax_id' => $request->filled('tax_id') ? trim((string) $request->input('tax_id')) : null]);
        $data = $request->validate([
            'tax_id' => ['nullable', 'string', 'max:20', 'unique:suppliers,tax_id'],
            'name' => ['required', 'string', 'max:180'], 'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'], 'address' => ['nullable', 'string', 'max:2000'],
        ]);
        $data['name'] = trim($data['name']);
        $supplier = Supplier::query()->create($data);

        return response()->json(['data' => $supplier], 201);
    }
}
