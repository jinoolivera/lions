<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaleReturn extends Model
{
    protected $fillable = ['sale_id', 'cash_session_id', 'user_id', 'return_number', 'operation_key', 'payload_hash', 'reason', 'refund_method', 'amount_cents', 'refund_cents', 'returned_at'];
    protected function casts(): array { return ['amount_cents' => 'integer', 'refund_cents' => 'integer', 'returned_at' => 'immutable_datetime']; }
    public function sale(): BelongsTo { return $this->belongsTo(Sale::class); }
    public function lines(): HasMany { return $this->hasMany(SaleReturnLine::class); }
}
