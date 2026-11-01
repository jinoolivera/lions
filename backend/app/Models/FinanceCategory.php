<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinanceCategory extends Model
{
    protected $fillable = ['name', 'type', 'is_active'];
    protected function casts(): array { return ['is_active' => 'boolean']; }
    public function transactions(): HasMany { return $this->hasMany(FinancialTransaction::class); }
}
