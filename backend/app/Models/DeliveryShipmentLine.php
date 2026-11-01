<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeliveryShipmentLine extends Model
{
    protected $fillable = ['delivery_shipment_id', 'delivery_order_line_id', 'quantity'];
    protected function casts(): array { return ['quantity' => 'decimal:6']; }
}
