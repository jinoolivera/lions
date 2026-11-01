<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashSession extends Model
{
    protected $fillable = ['warehouse_id', 'user_id', 'opening_cents', 'expected_cents', 'counted_cents', 'status', 'opened_at', 'closed_at'];
    protected function casts(): array { return ['opening_cents' => 'integer', 'expected_cents' => 'integer', 'counted_cents' => 'integer', 'opened_at' => 'immutable_datetime', 'closed_at' => 'immutable_datetime']; }
    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class); }
    public function sales(): HasMany { return $this->hasMany(Sale::class); }
}
