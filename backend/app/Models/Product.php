<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'category_id', 'sku', 'barcode', 'name', 'brand', 'base_unit', 'minimum_stock',
        'allows_fractional_quantity', 'is_active',
    ];

    protected function casts(): array
    {
        return ['minimum_stock' => 'decimal:6', 'allows_fractional_quantity' => 'boolean', 'is_active' => 'boolean'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    public function presentations(): HasMany
    {
        return $this->hasMany(ProductPresentation::class)->orderByDesc('is_base')->orderBy('id');
    }

    public function stockBalances(): HasMany
    {
        return $this->hasMany(StockBalance::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }
}
