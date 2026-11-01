<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductPresentation;
use App\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $warehouse = Warehouse::query()->with('locations')->where('is_active', true)->find($request->integer('warehouse_id'))
            ?? Warehouse::query()->with('locations')->where('is_active', true)->orderBy('id')->first();
        $locationId = $warehouse?->locations->firstWhere('is_default', true)?->id
            ?? $warehouse?->locations->first()?->id;
        $includeInactive = $request->boolean('include_inactive');
        $query = Product::query()->with([
            'presentations' => fn ($presentations) => $presentations->when(! $includeInactive, fn ($q) => $q->where('is_active', true)),
            'category',
        ])->when(! $includeInactive, fn ($products) => $products->where('is_active', true));
        if ($locationId) {
            $query->with(['stockBalances' => fn ($balances) => $balances->where('warehouse_location_id', $locationId)]);
        }

        $products = $query->orderBy('name')->get()->map(function (Product $product) use ($locationId): array {
            $balance = $locationId ? $product->stockBalances->first() : null;
            $physical = (string) ($balance?->physical_quantity ?? '0.000000');
            $reserved = (string) ($balance?->reserved_quantity ?? '0.000000');

            return [
                'id' => $product->id, 'sku' => $product->sku, 'barcode' => $product->barcode,
                'name' => $product->name, 'brand' => $product->brand, 'category_id' => $product->category_id,
                'category_name' => $product->category?->name, 'is_active' => $product->is_active, 'base_unit' => $product->base_unit,
                'minimum_stock' => $product->minimum_stock, 'allows_fractional_quantity' => $product->allows_fractional_quantity,
                'presentations' => $product->presentations->map(fn (ProductPresentation $presentation): array => [
                    'id' => $presentation->id, 'name' => $presentation->name,
                    'factor_to_base' => $presentation->factor_to_base,
                    'price_cents' => $presentation->price_cents, 'wholesale_price_cents' => $presentation->wholesale_price_cents,
                    'is_base' => $presentation->is_base, 'is_active' => $presentation->is_active,
                ])->all(),
                'stock' => [
                    'physical_quantity' => $physical,
                    'reserved_quantity' => $reserved,
                    'available_quantity' => (string) \Brick\Math\BigDecimal::of($physical)->minus($reserved),
                    'average_unit_cost_cents' => $balance?->average_unit_cost_cents,
                    'location_id' => $locationId,
                ],
            ];
        });

        return response()->json(['data' => $products, 'warehouse_id' => $warehouse?->id]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->merge([
            'sku' => strtoupper(trim((string) $request->input('sku'))),
            'barcode' => $request->filled('barcode') ? trim((string) $request->input('barcode')) : null,
        ]);
        $data = $request->validate([
            'sku' => ['required', 'string', 'max:64', 'unique:products,sku'],
            'barcode' => ['nullable', 'string', 'max:64', 'unique:products,barcode'],
            'name' => ['required', 'string', 'max:180'],
            'brand' => ['nullable', 'string', Rule::exists('product_brands', 'name')->where('is_active', true)],
            'base_unit' => ['required', 'string', Rule::exists('product_units', 'name')->where('is_active', true)],
            'minimum_stock' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'allows_fractional_quantity' => ['sometimes', 'boolean'],
            'category_id' => ['nullable', 'integer', 'exists:product_categories,id'],
            'presentations' => ['required', 'array', 'min:1', 'max:1'],
            'presentations.0.name' => ['required', 'string', 'max:80'],
            'presentations.0.factor_to_base' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'presentations.0.price_cents' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'presentations.0.wholesale_price_cents' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'presentations.0.is_base' => ['required', 'accepted'],
        ]);

        if (\Brick\Math\BigDecimal::of((string) $data['presentations'][0]['factor_to_base'])
            ->compareTo(\Brick\Math\BigDecimal::of('1')) !== 0) {
            abort(422, 'La presentación base debe convertir exactamente 1 a 1.');
        }

        $product = DB::transaction(function () use ($data): Product {
            $product = Product::query()->create([
                'category_id' => $data['category_id'] ?? null,
                'sku' => trim($data['sku']), 'barcode' => $data['barcode'] ?? null,
                'name' => trim($data['name']), 'brand' => isset($data['brand']) ? trim($data['brand']) : null,
                'base_unit' => trim($data['base_unit']), 'minimum_stock' => $data['minimum_stock'],
                'allows_fractional_quantity' => $data['allows_fractional_quantity'] ?? false,
            ]);
            $product->presentations()->create([
                'name' => trim($data['presentations'][0]['name']), 'factor_to_base' => '1.000000',
                'price_cents' => $data['presentations'][0]['price_cents'], 'is_base' => true,
                'wholesale_price_cents' => $data['presentations'][0]['wholesale_price_cents'] ?? null,
            ]);

            return $product;
        });

        return response()->json(['data' => $product->load('presentations')], 201);
    }

    public function addPresentation(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('product_presentations')->where('product_id', $product->id)],
            'factor_to_base' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'price_cents' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'wholesale_price_cents' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
        ]);
        $factor = \Brick\Math\BigDecimal::of((string) $data['factor_to_base']);
        if ($factor->getScale() > 6) {
            abort(422, 'La equivalencia admite hasta seis decimales.');
        }

        $presentation = $product->presentations()->create([
            'name' => trim($data['name']), 'factor_to_base' => $factor->toScale(6)->__toString(),
            'price_cents' => $data['price_cents'], 'is_base' => false,
            'wholesale_price_cents' => $data['wholesale_price_cents'] ?? null,
        ]);

        return response()->json(['data' => $presentation], 201);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:180'], 'brand' => ['nullable', 'string', Rule::exists('product_brands', 'name')->where('is_active', true)],
            'barcode' => ['nullable', 'string', 'max:64', Rule::unique('products', 'barcode')->ignore($product->id)],
            'category_id' => ['nullable', 'integer', 'exists:product_categories,id'],
            'minimum_stock' => ['sometimes', 'numeric', 'min:0', 'max:999999999999'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $product->fill($data)->save();
        return response()->json(['data' => $product->fresh()->load('category')]);
    }

    public function updatePresentation(Request $request, Product $product, ProductPresentation $presentation): JsonResponse
    {
        abort_unless($presentation->product_id === $product->id, 404);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:80', Rule::unique('product_presentations')->where('product_id', $product->id)->ignore($presentation->id)],
            'factor_to_base' => ['sometimes', 'numeric', 'gt:0', 'max:999999999999'],
            'price_cents' => ['sometimes', 'integer', 'min:0', 'max:999999999999'],
            'wholesale_price_cents' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        if (array_key_exists('factor_to_base', $data)) {
            $factor = \Brick\Math\BigDecimal::of((string) $data['factor_to_base']);
            abort_if($factor->getScale() > 6, 422, 'La equivalencia admite hasta seis decimales.');
            if ($factor->compareTo(\Brick\Math\BigDecimal::of($presentation->factor_to_base)) !== 0) {
                $used = $product->movements()->where('product_presentation_id', $presentation->id)->exists()
                    || \App\Models\PurchaseOrderLine::query()->where('product_presentation_id', $presentation->id)->exists()
                    || \App\Models\SaleLine::query()->where('product_presentation_id', $presentation->id)->exists();
                abort_if($used, 409, 'Esta presentación ya tiene historial. Desactívala y crea otra presentación; el historial no se convierte automáticamente.');
                $data['factor_to_base'] = $factor->toScale(6)->__toString();
            }
        }
        $presentation->fill($data)->save();
        return response()->json(['data' => $presentation->fresh()]);
    }
}
