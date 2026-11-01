<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ProductCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductCategoryController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => ProductCategory::query()->orderBy('name')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120', 'unique:product_categories,name']]);
        return response()->json(['data' => ProductCategory::query()->create(['name' => trim($data['name'])])], 201);
    }

    public function update(Request $request, ProductCategory $productCategory): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120', Rule::unique('product_categories', 'name')->ignore($productCategory->id)],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $productCategory->fill($data)->save();
        return response()->json(['data' => $productCategory->fresh()]);
    }
}
