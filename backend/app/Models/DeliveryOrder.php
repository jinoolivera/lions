<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeliveryOrder extends Model
{
    protected $fillable = ['warehouse_id', 'warehouse_location_id', 'customer_id', 'user_id', 'cancelled_by', 'cancelled_at', 'order_number', 'operation_key', 'payload_hash', 'status', 'source', 'notes', 'delivery_address', 'delivery_contact', 'dispatch_responsible', 'ordered_at'];
    protected function casts(): array { return ['ordered_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime']; }
    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function lines(): HasMany { return $this->hasMany(DeliveryOrderLine::class); }
    public function shipments(): HasMany { return $this->hasMany(DeliveryShipment::class); }
}
