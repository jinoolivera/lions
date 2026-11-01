<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockBalance extends Model
{
    protected $fillable = ['warehouse_location_id', 'product_id', 'physical_quantity', 'reserved_quantity', 'average_unit_cost_cents'];

    protected function casts(): array
    {
        return ['physical_quantity' => 'decimal:6', 'reserved_quantity' => 'decimal:6', 'average_unit_cost_cents' => 'decimal:6'];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'warehouse_location_id');
    }
}
