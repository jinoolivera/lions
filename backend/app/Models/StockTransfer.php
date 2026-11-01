<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockTransfer extends Model
{
    protected $fillable = ['source_warehouse_id', 'destination_warehouse_id', 'source_location_id', 'destination_location_id', 'user_id', 'transfer_number', 'operation_key', 'payload_hash', 'status', 'notes', 'shipped_at', 'received_at'];
    protected function casts(): array { return ['shipped_at' => 'immutable_datetime', 'received_at' => 'immutable_datetime']; }
    public function source(): BelongsTo { return $this->belongsTo(Warehouse::class, 'source_warehouse_id'); }
    public function destination(): BelongsTo { return $this->belongsTo(Warehouse::class, 'destination_warehouse_id'); }
    public function lines(): HasMany { return $this->hasMany(StockTransferLine::class); }
    public function receipts(): HasMany { return $this->hasMany(StockTransferReceipt::class); }
}
