<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeliveryPick extends Model
{
    protected $fillable = ['delivery_order_id', 'user_id', 'pick_key', 'payload_hash', 'notes', 'picked_at'];
    protected function casts(): array { return ['picked_at' => 'immutable_datetime']; }
    public function lines(): HasMany { return $this->hasMany(DeliveryPickLine::class); }
}
