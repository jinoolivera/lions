<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function overview(Request $request)
    {
        $filters = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
        ]);

        $localTimezone = config('app.timezone');
        $periodStartUtc = CarbonImmutable::parse($filters['from'], $localTimezone)->startOfDay()->utc()->format('Y-m-d H:i:s');
        $periodEndUtc = CarbonImmutable::parse($filters['to'], $localTimezone)->addDay()->startOfDay()->utc()->format('Y-m-d H:i:s');

        $sales = DB::table('sales')
            ->where('sold_at', '>=', $periodStartUtc)
            ->where('sold_at', '<', $periodEndUtc)
            ->when($filters['warehouse_id'] ?? null, fn ($query, $id) => $query->where('warehouse_id', $id));

        $salesSummary = (clone $sales)->selectRaw(
            "COUNT(*) as count, COALESCE(SUM(total_cents + returned_cents), 0) as issued_cents, COALESCE(SUM(paid_cents), 0) as paid_cents"
        )->first();
        $saleRefundsInPeriod = DB::table('sale_returns')->join('sales', 'sales.id', '=', 'sale_returns.sale_id')
            ->where('sale_returns.returned_at', '>=', $periodStartUtc)->where('sale_returns.returned_at', '<', $periodEndUtc)
            ->when($filters['warehouse_id'] ?? null, fn ($query, $id) => $query->where('sales.warehouse_id', $id))
            ->sum('sale_returns.amount_cents');
        $salesRevenueCents = (int) $salesSummary->issued_cents - (int) $saleRefundsInPeriod;

        $stockQuery = DB::table('products')
            ->leftJoin('stock_balances', 'products.id', '=', 'stock_balances.product_id')
            ->leftJoin('warehouse_locations', function ($join) use ($filters): void {
                $join->on('warehouse_locations.id', '=', 'stock_balances.warehouse_location_id');
                if (! empty($filters['warehouse_id'])) {
                    $join->where('warehouse_locations.warehouse_id', '=', $filters['warehouse_id']);
                }
            })
            ->where('products.is_active', true)
            ;
        $stockRows = (clone $stockQuery)->selectRaw(
            'products.id, products.name, products.sku, products.base_unit, products.minimum_stock,
             COALESCE(SUM(CASE WHEN warehouse_locations.id IS NOT NULL THEN stock_balances.physical_quantity ELSE 0 END), 0) as physical_quantity,
             COALESCE(SUM(CASE WHEN warehouse_locations.id IS NOT NULL THEN stock_balances.reserved_quantity ELSE 0 END), 0) as reserved_quantity,
             COALESCE(SUM(CASE WHEN warehouse_locations.id IS NOT NULL THEN stock_balances.physical_quantity * COALESCE(stock_balances.average_unit_cost_cents, 0) ELSE 0 END), 0) as estimated_value_cents,
             MAX(CASE WHEN warehouse_locations.id IS NOT NULL AND stock_balances.average_unit_cost_cents IS NULL AND stock_balances.physical_quantity > 0 THEN 1 ELSE 0 END) as has_unknown_cost'
        )->groupBy('products.id', 'products.name', 'products.sku', 'products.base_unit', 'products.minimum_stock')->get();

        $lowStock = $stockRows->filter(fn ($row) => (float) $row->physical_quantity - (float) $row->reserved_quantity <= (float) $row->minimum_stock)->count();
        $inventoryValue = (int) round($stockRows->sum(fn ($row) => (float) $row->estimated_value_cents));
        $unknownCostProducts = $stockRows->filter(fn ($row) => (bool) $row->has_unknown_cost)->count();

        $cashQuery = DB::table('cash_sessions')
            ->when($filters['warehouse_id'] ?? null, fn ($query, $id) => $query->where('warehouse_id', $id));
        $closedCash = (clone $cashQuery)->where('status', 'closed')->whereDate('closed_at', '>=', $filters['from'])->whereDate('closed_at', '<=', $filters['to']);
        $cashSummary = (clone $closedCash)->selectRaw(
            'COUNT(*) as closed_count, COALESCE(SUM(CAST(counted_cents AS SIGNED) - CAST(expected_cents AS SIGNED)), 0) as variance_cents'
        )->first();

        $productSales = DB::table('sale_lines')->join('sales', 'sales.id', '=', 'sale_lines.sale_id')
            ->where('sales.sold_at', '>=', $periodStartUtc)->where('sales.sold_at', '<', $periodEndUtc)
            ->when($filters['warehouse_id'] ?? null, fn ($query, $id) => $query->where('sales.warehouse_id', $id))
            ->selectRaw('sale_lines.product_id, MAX(sale_lines.product_name_snapshot) as name, SUM(sale_lines.quantity * sale_lines.factor_to_base) as quantity, SUM(sale_lines.line_total_cents) as total_cents')
            ->groupBy('sale_lines.product_id')->get()->keyBy('product_id');
        $productReturns = DB::table('sale_return_lines')->join('sale_returns', 'sale_returns.id', '=', 'sale_return_lines.sale_return_id')
            ->join('sales', 'sales.id', '=', 'sale_returns.sale_id')->join('sale_lines', 'sale_lines.id', '=', 'sale_return_lines.sale_line_id')
            ->where('sale_returns.returned_at', '>=', $periodStartUtc)->where('sale_returns.returned_at', '<', $periodEndUtc)
            ->when($filters['warehouse_id'] ?? null, fn ($query, $id) => $query->where('sales.warehouse_id', $id))
            ->selectRaw('sale_lines.product_id, SUM(sale_return_lines.quantity * sale_lines.factor_to_base) as quantity, SUM(sale_return_lines.amount_cents) as total_cents')
            ->groupBy('sale_lines.product_id')->get()->keyBy('product_id');
        $productCostRows = DB::table('inventory_movements')
            ->join('warehouse_locations', 'warehouse_locations.id', '=', 'inventory_movements.warehouse_location_id')
            ->whereIn('inventory_movements.type', ['sale', 'sale_return'])
            ->where('inventory_movements.occurred_at', '>=', $periodStartUtc)->where('inventory_movements.occurred_at', '<', $periodEndUtc)
            ->when($filters['warehouse_id'] ?? null, fn ($query, $id) => $query->where('warehouse_locations.warehouse_id', $id))
            ->selectRaw("inventory_movements.product_id, SUM(CASE WHEN inventory_movements.type = 'sale' THEN COALESCE(inventory_movements.extended_cost_cents, 0) ELSE -COALESCE(inventory_movements.extended_cost_cents, 0) END) as cost_cents, SUM(CASE WHEN inventory_movements.extended_cost_cents IS NULL THEN 1 ELSE 0 END) as unknown_cost_count")
            ->groupBy('inventory_movements.product_id')->get()->keyBy('product_id');
        $productIds = $productSales->keys()->merge($productReturns->keys())->unique();
        $topProducts = $productIds->map(function ($productId) use ($productSales, $productReturns, $productCostRows) {
            $sold = $productSales->get($productId);
            $returned = $productReturns->get($productId);
            $cost = $productCostRows->get($productId);
            $quantity = (float) ($sold->quantity ?? 0) - (float) ($returned->quantity ?? 0);
            $revenue = (int) ($sold->total_cents ?? 0) - (int) ($returned->total_cents ?? 0);
            $costCents = (int) ($cost->cost_cents ?? 0);
            return (object) [
                'product_id' => $productId, 'name' => $sold->name ?? DB::table('products')->where('id', $productId)->value('name') ?? 'Producto eliminado',
                'quantity' => $quantity, 'total_cents' => $revenue, 'cost_cents' => $costCents,
                'gross_profit_cents' => $revenue - $costCents,
                'gross_margin_percent' => $revenue > 0 ? round(($revenue - $costCents) / $revenue * 100, 2) : null,
                'cost_complete' => $cost !== null && (int) $cost->unknown_cost_count === 0,
            ];
        })->filter(fn ($product) => $product->quantity > 0)->sort(function ($left, $right) {
            return ($right->quantity <=> $left->quantity) ?: ($right->total_cents <=> $left->total_cents);
        })->take(10)->values();

        $costMovements = DB::table('inventory_movements')
            ->join('warehouse_locations', 'warehouse_locations.id', '=', 'inventory_movements.warehouse_location_id')
            ->whereIn('inventory_movements.type', ['sale', 'sale_return'])
            ->where('inventory_movements.occurred_at', '>=', $periodStartUtc)->where('inventory_movements.occurred_at', '<', $periodEndUtc)
            ->when($filters['warehouse_id'] ?? null, fn ($query, $id) => $query->where('warehouse_locations.warehouse_id', $id));
        $soldCogsCents = (int) (clone $costMovements)->where('inventory_movements.type', 'sale')->sum('inventory_movements.extended_cost_cents');
        $returnedCogsCents = (int) (clone $costMovements)->where('inventory_movements.type', 'sale_return')->sum('inventory_movements.extended_cost_cents');
        $cogsCents = $soldCogsCents - $returnedCogsCents;
        $uncostedSaleMovements = (clone $costMovements)->whereNull('inventory_movements.extended_cost_cents')->count();
        $saleCostRows = DB::table('inventory_movements')->whereNotNull('sale_id')->whereIn('type', ['sale', 'sale_return'])
            ->selectRaw("sale_id, SUM(CASE WHEN type = 'sale' THEN COALESCE(extended_cost_cents, 0) ELSE -COALESCE(extended_cost_cents, 0) END) as cost_cents, SUM(CASE WHEN type = 'sale' THEN 1 ELSE 0 END) as sale_movement_count, SUM(CASE WHEN extended_cost_cents IS NULL THEN 1 ELSE 0 END) as unknown_cost_count")
            ->groupBy('sale_id');
        $profitBySale = (clone $sales)->leftJoinSub($saleCostRows, 'sale_costs', fn ($join) => $join->on('sales.id', '=', 'sale_costs.sale_id'))
            ->selectRaw("sales.id, sales.sale_number, sales.sold_at, COALESCE(sales.customer_name, 'Venta al público') as customer_name, sales.total_cents, COALESCE(sale_costs.cost_cents, 0) as cost_cents, COALESCE(sale_costs.sale_movement_count, 0) as sale_movement_count, COALESCE(sale_costs.unknown_cost_count, 0) as unknown_cost_count")
            ->whereNotIn('sales.status', ['cancelled', 'returned'])
            ->latest('sales.sold_at')->get()->map(function ($sale) {
                $sale->gross_profit_cents = (int) $sale->total_cents - (int) $sale->cost_cents;
                $sale->gross_margin_percent = (int) $sale->total_cents > 0 ? round($sale->gross_profit_cents / (int) $sale->total_cents * 100, 2) : null;
                $sale->cost_complete = (int) $sale->sale_movement_count > 0 && (int) $sale->unknown_cost_count === 0;
                return $sale;
            });
        $financeQuery = DB::table('financial_transactions')
            ->where('occurred_at', '>=', $periodStartUtc)->where('occurred_at', '<', $periodEndUtc)
            ->when($filters['warehouse_id'] ?? null, fn ($query, $id) => $query->where('warehouse_id', $id));
        $financeAmounts = (clone $financeQuery)->selectRaw(
            "COALESCE(SUM(CASE WHEN type = 'income' THEN amount_cents ELSE 0 END), 0) as other_income_cents,
             COALESCE(SUM(CASE WHEN type = 'expense' THEN amount_cents ELSE 0 END), 0) as operating_expense_cents"
        )->first();
        $supplierPaymentsQuery = DB::table('supplier_invoice_payments')->join('supplier_invoices', 'supplier_invoices.id', '=', 'supplier_invoice_payments.supplier_invoice_id')
            ->whereDate('supplier_invoice_payments.paid_at', '>=', $filters['from'])->whereDate('supplier_invoice_payments.paid_at', '<=', $filters['to']);
        if (! empty($filters['warehouse_id'])) {
            $supplierPaymentsQuery->whereExists(function ($query) use ($filters): void {
                $query->selectRaw('1')->from('purchase_receipts')->whereColumn('purchase_receipts.purchase_order_id', 'supplier_invoices.purchase_order_id')
                    ->where('purchase_receipts.warehouse_id', $filters['warehouse_id']);
            });
        }
        $supplierPaymentsCents = (int) $supplierPaymentsQuery->sum('amount_cents');
        $purchasesQuery = DB::table('purchase_order_lines')->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_lines.purchase_order_id')
            ->whereDate('purchase_orders.ordered_at', '>=', $filters['from'])->whereDate('purchase_orders.ordered_at', '<=', $filters['to']);
        if (! empty($filters['warehouse_id'])) {
            $purchasesQuery->whereExists(function ($query) use ($filters): void {
                $query->selectRaw('1')->from('purchase_receipts')->whereColumn('purchase_receipts.purchase_order_id', 'purchase_orders.id')
                    ->where('purchase_receipts.warehouse_id', $filters['warehouse_id']);
            });
        }
        $purchasesCents = (int) round((float) $purchasesQuery->sum(DB::raw('purchase_order_lines.ordered_quantity * purchase_order_lines.unit_cost_cents')));
        $salesBySeller = DB::table('sales')->leftJoin('users', 'users.id', '=', 'sales.user_id')
            ->whereDate('sales.sold_at', '>=', $filters['from'])->whereDate('sales.sold_at', '<=', $filters['to'])
            ->when($filters['warehouse_id'] ?? null, fn ($query, $id) => $query->where('sales.warehouse_id', $id))
            ->selectRaw("COALESCE(users.name, 'Usuario eliminado') as seller, COUNT(*) as sales_count, COALESCE(SUM(sales.total_cents), 0) as total_cents")
            ->groupBy('sales.user_id', 'users.name')->orderByDesc('total_cents')->get();
        $overdue = DB::table('sales')->whereNotNull('customer_id')->whereNotNull('due_date')->whereDate('due_date', '<', today())
            ->whereColumn('total_cents', '>', 'paid_cents')
            ->when($filters['warehouse_id'] ?? null, fn ($query, $id) => $query->where('warehouse_id', $id));
        $receivables = DB::table('sales')->whereNotNull('customer_id')->whereColumn('total_cents', '>', 'paid_cents')
            ->when($filters['warehouse_id'] ?? null, fn ($query, $id) => $query->where('warehouse_id', $id));
        $pendingOrders = DB::table('delivery_orders')->whereNotIn('status', ['delivered', 'cancelled'])
            ->when($filters['warehouse_id'] ?? null, fn ($query, $id) => $query->where('warehouse_id', $id));
        $supplierPayables = DB::table('supplier_invoices')->whereColumn('total_cents', '>', 'paid_cents');
        if (! empty($filters['warehouse_id'])) {
            $supplierPayables->whereExists(function ($query) use ($filters): void {
                $query->selectRaw('1')->from('purchase_receipts')->whereColumn('purchase_receipts.purchase_order_id', 'supplier_invoices.purchase_order_id')
                    ->where('purchase_receipts.warehouse_id', $filters['warehouse_id']);
            });
        }
        $grossProfitCents = $salesRevenueCents - $cogsCents;
        $operatingExpenseCents = (int) $financeAmounts->operating_expense_cents;
        $collectedSalesCents = (int) DB::table('sale_payments')->whereIn('kind', ['sale', 'collection', 'advance'])
            ->whereDate('created_at', '>=', $filters['from'])->whereDate('created_at', '<=', $filters['to'])->sum('amount_cents');
        $saleRefundsCents = (int) DB::table('sale_returns')->join('sales', 'sales.id', '=', 'sale_returns.sale_id')
            ->whereDate('sale_returns.returned_at', '>=', $filters['from'])->whereDate('sale_returns.returned_at', '<=', $filters['to'])
            ->when($filters['warehouse_id'], fn ($query) => $query->where('sales.warehouse_id', $filters['warehouse_id']))->sum('sale_returns.refund_cents');
        $cashflowNetCents = $collectedSalesCents + (int) $financeAmounts->other_income_cents - $supplierPaymentsCents - $operatingExpenseCents - $saleRefundsCents;

        return response()->json(['data' => [
            'period' => ['from' => $filters['from'], 'to' => $filters['to']],
            'sales' => ['count' => (int) $salesSummary->count, 'total_cents' => $salesRevenueCents, 'paid_cents' => (int) $salesSummary->paid_cents, 'balance_cents' => $salesRevenueCents - (int) $salesSummary->paid_cents],
            'inventory' => ['products_count' => $stockRows->count(), 'low_stock_count' => $lowStock, 'estimated_value_cents' => $inventoryValue, 'products_with_unknown_cost' => $unknownCostProducts],
            'cash' => ['closed_sessions_count' => (int) $cashSummary->closed_count, 'closed_variance_cents' => (int) $cashSummary->variance_cents, 'open_sessions_count' => (clone $cashQuery)->where('status', 'open')->count()],
            'top_products' => $topProducts,
            'profit_by_sale' => $profitBySale,
            'finance' => [
                'revenue_cents' => $salesRevenueCents, 'cost_of_goods_cents' => $cogsCents,
                'gross_profit_cents' => $grossProfitCents, 'gross_margin_percent' => $salesRevenueCents > 0 ? round($grossProfitCents / $salesRevenueCents * 100, 2) : null,
                'operating_expense_cents' => $operatingExpenseCents, 'other_income_cents' => (int) $financeAmounts->other_income_cents,
                'estimated_net_profit_cents' => $grossProfitCents + (int) $financeAmounts->other_income_cents - $operatingExpenseCents,
                'supplier_payments_cents' => $supplierPaymentsCents, 'sale_refunds_cents' => $saleRefundsCents, 'purchases_ordered_cents' => $purchasesCents,
                'collected_sales_cents' => $collectedSalesCents, 'cashflow_net_cents' => $cashflowNetCents,
                'sales_without_cost_count' => $uncostedSaleMovements,
            ],
            'management' => [
                'sales_by_seller' => $salesBySeller, 'receivables_cents' => (int) $receivables->sum(DB::raw('total_cents - paid_cents')),
                'debtors_count' => (clone $receivables)->distinct('customer_id')->count('customer_id'),
                'overdue_receivables_cents' => (int) $overdue->sum(DB::raw('total_cents - paid_cents')),
                'overdue_sales_count' => (clone $overdue)->count(), 'pending_delivery_orders_count' => $pendingOrders->count(),
                'payables_cents' => (int) $supplierPayables->sum(DB::raw('total_cents - paid_cents')),
                'open_supplier_invoices_count' => (clone $supplierPayables)->count(),
                'open_cash_expected_cents' => (int) (clone $cashQuery)->where('status', 'open')->sum('expected_cents'),
            ],
        ]]);
    }
}
