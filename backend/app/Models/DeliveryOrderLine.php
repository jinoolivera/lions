<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryOrderLine extends Model
{
    protected $fillable = ['delivery_order_id', 'product_id', 'product_presentation_id', 'product_name_snapshot', 'presentation_name_snapshot', 'factor_to_base', 'ordered_quantity', 'picked_quantity', 'delivered_quantity', 'unit_price_cents'];
    protected function casts(): array { return ['factor_to_base' => 'decimal:6', 'ordered_quantity' => 'decimal:6', 'picked_quantity' => 'decimal:6', 'delivered_quantity' => 'decimal:6', 'unit_price_cents' => 'integer']; }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function presentation(): BelongsTo { return $this->belongsTo(ProductPresentation::class, 'product_presentation_id'); }
}
