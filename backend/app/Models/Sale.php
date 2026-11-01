<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sale extends Model
{
    protected $fillable = ['cash_session_id', 'warehouse_id', 'warehouse_location_id', 'user_id', 'customer_id', 'sale_number', 'operation_key', 'payload_hash', 'customer_name', 'subtotal_cents', 'total_cents', 'paid_cents', 'status', 'sold_at', 'due_date'];
    protected function casts(): array { return ['subtotal_cents' => 'integer', 'total_cents' => 'integer', 'paid_cents' => 'integer', 'returned_cents' => 'integer', 'sold_at' => 'immutable_datetime', 'due_date' => 'date:Y-m-d']; }
    public function cashSession(): BelongsTo { return $this->belongsTo(CashSession::class); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function lines(): HasMany { return $this->hasMany(SaleLine::class); }
    public function payments(): HasMany { return $this->hasMany(SalePayment::class); }
    public function returns(): HasMany { return $this->hasMany(SaleReturn::class); }
    public function documents(): HasMany { return $this->hasMany(SaleDocument::class); }
}
