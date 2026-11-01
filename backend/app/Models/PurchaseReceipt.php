<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseReceipt extends Model
{
    protected $fillable = ['purchase_order_id', 'warehouse_id', 'warehouse_location_id', 'user_id', 'receipt_key', 'payload_hash', 'supplier_document_reference', 'notes', 'received_at'];
    protected function casts(): array { return ['received_at' => 'immutable_datetime']; }
    public function order(): BelongsTo { return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id'); }
    public function lines(): HasMany { return $this->hasMany(PurchaseReceiptLine::class); }
}
