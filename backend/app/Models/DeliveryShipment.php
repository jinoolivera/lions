<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DeliveryShipment extends Model
{
    protected $fillable = ['delivery_order_id', 'user_id', 'sale_id', 'shipment_key', 'payload_hash', 'notes', 'delivered_at'];
    protected function casts(): array { return ['delivered_at' => 'immutable_datetime']; }
    public function order(): BelongsTo { return $this->belongsTo(DeliveryOrder::class, 'delivery_order_id'); }
    public function sale(): BelongsTo { return $this->belongsTo(Sale::class); }
    public function lines(): HasMany { return $this->hasMany(DeliveryShipmentLine::class); }
    public function guide(): HasOne { return $this->hasOne(DeliveryGuide::class); }
}
