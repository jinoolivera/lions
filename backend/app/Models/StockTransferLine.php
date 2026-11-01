<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockTransferLine extends Model
{
    protected $fillable = ['stock_transfer_id', 'product_id', 'product_presentation_id', 'product_name_snapshot', 'presentation_name_snapshot', 'factor_to_base', 'shipped_quantity', 'received_quantity', 'unit_cost_base_cents'];
    protected function casts(): array { return ['factor_to_base' => 'decimal:6', 'shipped_quantity' => 'decimal:6', 'received_quantity' => 'decimal:6', 'unit_cost_base_cents' => 'decimal:6']; }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function presentation(): BelongsTo { return $this->belongsTo(ProductPresentation::class, 'product_presentation_id'); }
}
