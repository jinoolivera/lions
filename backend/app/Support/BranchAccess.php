<?php

namespace App\Support;

use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;

class BranchAccess
{
    public static function warehouseIds(User $user): array
    {
        if ($user->role === 'admin') {
            return Warehouse::query()->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        return Warehouse::query()->whereIn('branch_id', $user->branches()->pluck('branches.id'))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public static function allowsWarehouse(User $user, int $warehouseId): bool
    {
        return $user->role === 'admin' || in_array($warehouseId, self::warehouseIds($user), true);
    }

    public static function requireWarehouse(User $user, int $warehouseId): void
    {
        abort_unless(self::allowsWarehouse($user, $warehouseId), 403, 'No tienes acceso a esta tienda.');
    }

    public static function scopeWarehouses(Builder $query, User $user): Builder
    {
        return $user->role === 'admin' ? $query : $query->whereIn('warehouse_id', self::warehouseIds($user));
    }
}
