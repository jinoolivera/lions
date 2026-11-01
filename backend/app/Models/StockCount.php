<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockCount extends Model
{
    protected $fillable = [
        'warehouse_id', 'warehouse_location_id', 'product_id', 'user_id', 'inventory_movement_id',
        'operation_key', 'payload_hash', 'system_quantity', 'counted_quantity', 'variance_quantity', 'reason', 'counted_at',
    ];

    protected function casts(): array
    {
        return ['system_quantity' => 'decimal:6', 'counted_quantity' => 'decimal:6', 'variance_quantity' => 'decimal:6', 'counted_at' => 'immutable_datetime'];
    }

    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function movement(): BelongsTo { return $this->belongsTo(InventoryMovement::class, 'inventory_movement_id'); }
}
