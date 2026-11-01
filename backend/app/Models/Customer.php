<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    protected $fillable = ['tax_id', 'tax_id_type', 'name', 'email', 'phone', 'address', 'credit_limit_cents', 'is_active'];
    protected function casts(): array { return ['credit_limit_cents' => 'integer', 'is_active' => 'boolean']; }
    public function sales(): HasMany { return $this->hasMany(Sale::class); }
}
