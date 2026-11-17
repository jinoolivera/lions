<?php

namespace App\Http\Middleware;

use App\Models\CashSession;
use App\Models\Sale;
use App\Support\BranchAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BranchStaffAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user?->role === 'admin') {
            return $next($request);
        }

        $path = $request->path();
        $method = $request->method();
        $allowedGet = [
            'api/v1/branches', 'api/v1/sunat/mode', 'api/v1/products',
            'api/v1/product-categories', 'api/v1/product-masters', 'api/v1/customers',
            'api/v1/warehouses', 'api/v1/cash/sessions', 'api/v1/sales', 'api/v1/sales/archive',
        ];
        $allowedPost = ['api/v1/customers', 'api/v1/cash/sessions', 'api/v1/sales'];
        $saleId = $request->route('sale');
        $saleId = $saleId instanceof Sale ? $saleId->id : $saleId;
        $cashSessionId = $request->route('cashSession');
        $cashSessionId = $cashSessionId instanceof CashSession ? $cashSessionId->id : $cashSessionId;
        $saleWarehouseId = $saleId ? (int) Sale::query()->whereKey($saleId)->value('warehouse_id') : null;
        $sessionWarehouseId = $cashSessionId ? (int) CashSession::query()->whereKey($cashSessionId)->value('warehouse_id') : null;

        $permitted = ($method === 'GET' && in_array($path, $allowedGet, true))
            || ($method === 'POST' && in_array($path, $allowedPost, true))
            || ($method === 'GET' && preg_match('#^api/v1/sales/\d+/ticket/link$#', $path))
            || ($method === 'GET' && preg_match('#^api/v1/sales/\d+(?:/documents/\d+(?:/pdf|/adjustments/\d+/pdf))?$#', $path))
            || ($method === 'PATCH' && preg_match('#^api/v1/sales/\d+/billing$#', $path))
            || ($method === 'POST' && preg_match('#^api/v1/sales/\d+/ticket/email$#', $path))
            || ($method === 'POST' && preg_match('#^api/v1/sales/\d+/shipments(?:/\d+/(?:dispatch|cancel))?$#', $path))
            || ($method === 'POST' && preg_match('#^api/v1/sales/\d+/shipments/\d+/guide/(?:pdf|email)$#', $path))
            || ($method === 'GET' && preg_match('#^api/v1/sales/\d+/shipments/\d+/guide/link$#', $path))
            || ($method === 'POST' && preg_match('#^api/v1/sales/\d+/payments$#', $path))
            || ($user->role === 'manager' && $method === 'POST' && preg_match('#^api/v1/sales/\d+/(returns|cancel)$#', $path))
            || ($method === 'POST' && preg_match('#^api/v1/cash/sessions/\d+/close$#', $path));
        abort_unless($permitted, 403, 'Este módulo requiere una cuenta administradora.');

        if ($request->filled('warehouse_id')) {
            BranchAccess::requireWarehouse($user, $request->integer('warehouse_id'));
        }
        if ($saleId) {
            abort_unless($saleWarehouseId, 404);
            BranchAccess::requireWarehouse($user, $saleWarehouseId);
        }
        if ($cashSessionId) {
            abort_unless($sessionWarehouseId, 404);
            BranchAccess::requireWarehouse($user, $sessionWarehouseId);
        }
        if ($path === 'api/v1/sales' && $method === 'POST') {
            $warehouseId = (int) CashSession::query()->whereKey($request->integer('cash_session_id'))->value('warehouse_id');
            abort_unless($warehouseId, 404);
            BranchAccess::requireWarehouse($user, $warehouseId);
        }

        return $next($request);
    }
}
