<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderLine extends Model
{
    protected $fillable = [
        'purchase_order_id', 'product_id', 'product_presentation_id', 'product_name_snapshot',
        'presentation_name_snapshot', 'factor_to_base', 'ordered_quantity', 'received_quantity', 'document_unit_cost_cents', 'unit_cost_cents',
    ];
    protected function casts(): array
    {
        return ['factor_to_base' => 'decimal:6', 'ordered_quantity' => 'decimal:6', 'received_quantity' => 'decimal:6', 'document_unit_cost_cents' => 'integer', 'unit_cost_cents' => 'integer'];
    }
    public function order(): BelongsTo { return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id'); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function presentation(): BelongsTo { return $this->belongsTo(ProductPresentation::class, 'product_presentation_id'); }
}
