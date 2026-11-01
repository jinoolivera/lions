<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierInvoice extends Model
{
    protected $fillable = ['supplier_id', 'purchase_order_id', 'user_id', 'document_type', 'series', 'number', 'issue_date', 'due_date', 'currency_code', 'document_total_cents', 'exchange_rate', 'taxable_base_cents', 'igv_cents', 'exempt_amount_cents', 'unaffected_amount_cents', 'other_taxes_cents', 'tax_breakdown_status', 'total_cents', 'order_amount_cents', 'variance_cents', 'reconciliation_status', 'reconciliation_note', 'paid_cents', 'status'];
    protected function casts(): array { return ['issue_date' => 'date', 'due_date' => 'date', 'document_total_cents' => 'integer', 'exchange_rate' => 'decimal:8', 'taxable_base_cents' => 'integer', 'igv_cents' => 'integer', 'exempt_amount_cents' => 'integer', 'unaffected_amount_cents' => 'integer', 'other_taxes_cents' => 'integer', 'total_cents' => 'integer', 'order_amount_cents' => 'integer', 'variance_cents' => 'integer', 'paid_cents' => 'integer']; }
    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }
    public function order(): BelongsTo { return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id'); }
    public function payments(): HasMany { return $this->hasMany(SupplierInvoicePayment::class); }
}
