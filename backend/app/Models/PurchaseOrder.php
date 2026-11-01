<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    protected $fillable = ['supplier_id', 'user_id', 'order_number', 'operation_key', 'payload_hash', 'status', 'notes', 'currency_code', 'exchange_rate', 'exchange_rate_date', 'document_landed_cost_cents', 'landed_cost_cents', 'landed_cost_allocated_cents', 'ordered_at'];
    protected function casts(): array { return ['ordered_at' => 'immutable_datetime', 'exchange_rate' => 'decimal:8', 'exchange_rate_date' => 'date', 'document_landed_cost_cents' => 'integer', 'landed_cost_cents' => 'integer', 'landed_cost_allocated_cents' => 'integer']; }
    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }
    public function lines(): HasMany { return $this->hasMany(PurchaseOrderLine::class); }
    public function receipts(): HasMany { return $this->hasMany(PurchaseReceipt::class); }
    public function invoices(): HasMany { return $this->hasMany(SupplierInvoice::class); }
}
