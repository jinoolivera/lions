<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    protected $fillable = ['tax_id', 'name', 'email', 'phone', 'address', 'is_active'];

    protected function casts(): array { return ['is_active' => 'boolean']; }

    public function purchaseOrders(): HasMany { return $this->hasMany(PurchaseOrder::class); }
    public function invoices(): HasMany { return $this->hasMany(SupplierInvoice::class); }
}
