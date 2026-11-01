<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialTransaction extends Model
{
    protected $fillable = ['finance_category_id', 'warehouse_id', 'cash_session_id', 'user_id', 'type', 'method', 'amount_cents', 'reference', 'description', 'operation_key', 'payload_hash', 'occurred_at'];
    protected function casts(): array { return ['amount_cents' => 'integer', 'occurred_at' => 'immutable_datetime']; }
    public function category(): BelongsTo { return $this->belongsTo(FinanceCategory::class, 'finance_category_id'); }
    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class); }
    public function cashSession(): BelongsTo { return $this->belongsTo(CashSession::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
