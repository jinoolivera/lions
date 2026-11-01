<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleLine extends Model
{
    protected $fillable = ['sale_id', 'product_id', 'product_presentation_id', 'product_name_snapshot', 'presentation_name_snapshot', 'factor_to_base', 'quantity', 'returned_quantity', 'unit_price_cents', 'subtotal_cents', 'discount_cents', 'discount_approved_by_user_id', 'line_total_cents'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    protected function casts(): array
    {
        return ['factor_to_base' => 'decimal:6', 'quantity' => 'decimal:6', 'returned_quantity' => 'decimal:6', 'unit_price_cents' => 'integer', 'subtotal_cents' => 'integer', 'discount_cents' => 'integer', 'discount_approved_by_user_id' => 'integer', 'line_total_cents' => 'integer'];
    }
}
