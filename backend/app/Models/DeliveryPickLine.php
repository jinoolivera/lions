<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeliveryPickLine extends Model
{
    protected $fillable = ['delivery_pick_id', 'delivery_order_line_id', 'quantity'];
    protected function casts(): array { return ['quantity' => 'decimal:6']; }
}
