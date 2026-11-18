<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PilotSaleNote extends Model
{
    protected $fillable = ['warehouse_id', 'user_id', 'sale_id', 'body', 'product_name', 'quantity', 'unit_price_cents', 'customer_name', 'payment_method', 'remarks', 'status', 'sold_at'];

    protected function casts(): array { return ['sold_at' => 'datetime']; }
}
