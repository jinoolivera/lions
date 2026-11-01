<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockTransferReceipt extends Model
{
    protected $fillable = ['stock_transfer_id', 'user_id', 'operation_key', 'payload_hash', 'received_at'];
    protected function casts(): array { return ['received_at' => 'immutable_datetime']; }
    public function transfer(): BelongsTo { return $this->belongsTo(StockTransfer::class, 'stock_transfer_id'); }
    public function lines(): HasMany { return $this->hasMany(StockTransferReceiptLine::class); }
}
