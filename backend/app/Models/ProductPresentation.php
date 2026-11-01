<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductPresentation extends Model
{
    protected $fillable = ['product_id', 'name', 'factor_to_base', 'price_cents', 'wholesale_price_cents', 'is_base', 'is_active'];

    protected function casts(): array
    {
        return ['factor_to_base' => 'decimal:6', 'price_cents' => 'integer', 'wholesale_price_cents' => 'integer', 'is_base' => 'boolean', 'is_active' => 'boolean'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
