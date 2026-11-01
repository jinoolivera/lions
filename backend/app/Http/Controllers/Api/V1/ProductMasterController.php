<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProductMasterController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => [
            'brands' => DB::table('product_brands')->orderBy('name')->get(),
            'units' => DB::table('product_units')->orderBy('name')->get(),
        ]]);
    }

    public function storeBrand(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120', 'unique:product_brands,name']]);
        $id = DB::table('product_brands')->insertGetId(['name' => trim($data['name']), 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(['data' => DB::table('product_brands')->find($id)], 201);
    }

    public function updateBrand(Request $request, int $id): JsonResponse
    {
        $brand = DB::table('product_brands')->find($id);
        abort_unless($brand, 404);
        $request->merge(['name' => is_string($request->input('name')) ? trim($request->input('name')) : $request->input('name')]);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120', Rule::unique('product_brands', 'name')->ignore($id)],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        DB::transaction(function () use ($data, $id, $brand): void {
            if (array_key_exists('name', $data) && $data['name'] !== $brand->name) {
                DB::table('products')->where('brand', $brand->name)->update(['brand' => trim($data['name']), 'updated_at' => now()]);
            }
            DB::table('product_brands')->where('id', $id)->update(array_merge($data, ['updated_at' => now()]));
        });
        return response()->json(['data' => DB::table('product_brands')->find($id)]);
    }

    public function storeUnit(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:24', 'unique:product_units,name']]);
        $id = DB::table('product_units')->insertGetId(['name' => trim($data['name']), 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(['data' => DB::table('product_units')->find($id)], 201);
    }

    public function updateUnit(Request $request, int $id): JsonResponse
    {
        $unit = DB::table('product_units')->find($id);
        abort_unless($unit, 404);
        $data = $request->validate([
            'is_active' => ['sometimes', 'boolean'],
            'name' => ['prohibited'],
        ]);
        DB::table('product_units')->where('id', $id)->update($data + ['updated_at' => now()]);
        return response()->json(['data' => DB::table('product_units')->find($id)]);
    }
}
