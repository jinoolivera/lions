<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseReceiptLine extends Model
{
    protected $fillable = ['purchase_receipt_id', 'purchase_order_line_id', 'quantity', 'unit_cost_cents', 'landed_cost_cents'];
    protected function casts(): array { return ['quantity' => 'decimal:6', 'unit_cost_cents' => 'integer', 'landed_cost_cents' => 'integer']; }
    public function receipt(): BelongsTo { return $this->belongsTo(PurchaseReceipt::class, 'purchase_receipt_id'); }
    public function orderLine(): BelongsTo { return $this->belongsTo(PurchaseOrderLine::class, 'purchase_order_line_id'); }
}
