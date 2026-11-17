<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Branch extends Model
{
    protected $fillable = ['name', 'address', 'invoice_series', 'receipt_series', 'is_active'];

    protected function casts(): array { return ['is_active' => 'boolean']; }

    public function warehouses(): HasMany { return $this->hasMany(Warehouse::class); }
    public function users(): BelongsToMany { return $this->belongsToMany(User::class)->withTimestamps(); }
}
