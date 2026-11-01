<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierInvoicePayment extends Model
{
    protected $fillable = ['supplier_invoice_id', 'user_id', 'operation_key', 'payload_hash', 'method', 'amount_cents', 'reference', 'paid_at'];

    protected function casts(): array
    {
        return ['amount_cents' => 'integer', 'paid_at' => 'immutable_datetime'];
    }

    public function invoice(): BelongsTo { return $this->belongsTo(SupplierInvoice::class, 'supplier_invoice_id'); }
}
