<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryGuide extends Model
{
    protected $fillable = ['delivery_shipment_id', 'user_id', 'series', 'number', 'issue_date', 'issued_via', 'status'];

    protected function casts(): array { return ['issue_date' => 'date:Y-m-d']; }
    public function shipment(): BelongsTo { return $this->belongsTo(DeliveryShipment::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
