<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryMovement extends Model
{
    protected $fillable = [
        'warehouse_location_id', 'product_id', 'sale_id', 'product_presentation_id', 'user_id', 'type',
        'quantity_delta', 'unit_cost_cents', 'extended_cost_cents', 'reason', 'operation_key', 'payload_hash', 'occurred_at',
    ];

    protected function casts(): array
    {
        return ['quantity_delta' => 'decimal:6', 'unit_cost_cents' => 'decimal:6', 'extended_cost_cents' => 'integer', 'occurred_at' => 'immutable_datetime'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'warehouse_location_id');
    }

    public function presentation(): BelongsTo
    {
        return $this->belongsTo(ProductPresentation::class, 'product_presentation_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
