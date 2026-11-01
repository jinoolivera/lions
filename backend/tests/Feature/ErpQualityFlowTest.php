<?php

namespace Tests\Feature;

use App\Models\CashSession;
use App\Models\Customer;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Sale;
use App\Models\StockBalance;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ErpQualityFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_localhost_and_loopback_sessions_can_open_cash_after_login(): void
    {
        $user = User::factory()->create([
            'email' => 'admin@lions.com',
            'password' => Hash::make('uw872301'),
        ]);
        $warehouse = Warehouse::query()->where('code', 'MAIN')->firstOrFail();

        foreach (['localhost', '127.0.0.1'] as $host) {
            $origin = "http://{$host}:8000";
            $this->withHeaders(['Origin' => $origin, 'Referer' => $origin.'/'])
                ->get('/sanctum/csrf-cookie')->assertNoContent();

            $this->postJson('/api/v1/auth/login', [
                'email' => $user->email,
                'password' => 'uw872301',
            ])->assertOk()->assertJsonPath('user.email', $user->email);

            $this->withHeaders(['Origin' => $origin, 'Referer' => $origin.'/'])
                ->getJson('/api/user')->assertOk()->assertJsonPath('email', $user->email);

            $this->withHeaders(['Origin' => $origin, 'Referer' => $origin.'/'])
                ->postJson('/api/v1/cash/sessions', [
                    'warehouse_id' => $warehouse->id,
                    'opening_cents' => 1000,
                ])->assertCreated();

            CashSession::query()->delete();
            $this->postJson('/api/v1/auth/logout')->assertOk();
        }
    }

    public function test_finance_expenses_update_cash_once_and_feed_management_reports(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'web');
        $warehouse = Warehouse::query()->where('code', 'MAIN')->firstOrFail();
        $session = $this->postJson('/api/v1/cash/sessions', [
            'warehouse_id' => $warehouse->id, 'opening_cents' => 10000,
        ])->assertCreated()->json('data');
        $categoryId = \App\Models\FinanceCategory::query()->where('type', 'expense')->value('id');
        $payload = [
            'operation_key' => 'quality-finance-expense-001', 'finance_category_id' => $categoryId,
            'warehouse_id' => $warehouse->id, 'cash_session_id' => $session['id'], 'type' => 'expense',
            'method' => 'cash', 'amount_cents' => 2500, 'description' => 'DEMO: compra de útiles',
            'occurred_at' => now()->toISOString(),
        ];
        $this->postJson('/api/v1/finance/transactions', $payload)->assertCreated();
        $this->postJson('/api/v1/finance/transactions', $payload)->assertCreated();
        $this->assertSame(7500, (int) CashSession::query()->findOrFail($session['id'])->expected_cents);
        $this->postJson('/api/v1/finance/transactions', array_replace($payload, [
            'operation_key' => 'quality-finance-expense-over-001', 'amount_cents' => 8000,
        ]))->assertUnprocessable();
        $this->assertSame(2500, (int) DB::table('financial_transactions')->sum('amount_cents'));
        $this->getJson('/api/v1/finance/transactions?from='.now()->toDateString().'&to='.now()->toDateString().'&warehouse_id='.$warehouse->id)
            ->assertOk()->assertJsonCount(1, 'data.transactions');

        $this->getJson('/api/v1/reports/overview?from='.now()->toDateString().'&to='.now()->toDateString().'&warehouse_id='.$warehouse->id)
            ->assertOk()->assertJsonPath('data.finance.operating_expense_cents', 2500)
            ->assertJsonPath('data.finance.cashflow_net_cents', -2500)
            ->assertJsonPath('data.management.debtors_count', 0)
            ->assertJsonPath('data.management.payables_cents', 0)
            ->assertJsonPath('data.management.open_cash_expected_cents', 7500)
            ->assertJsonPath('data.management.pending_delivery_orders_count', 0);
    }

    public function test_purchase_order_accepts_multiple_product_lines(): void
    {
        $this->actingAs(User::factory()->create(), 'web');
        $supplier = $this->postJson('/api/v1/suppliers', ['name' => 'Proveedor de prueba'])->assertCreated()->json('data');
        $products = [];
        foreach ([['SKU-MULTI-A', 'Producto A'], ['SKU-MULTI-B', 'Producto B']] as [$sku, $name]) {
            $products[] = $this->postJson('/api/v1/products', [
                'sku' => $sku, 'name' => $name, 'base_unit' => 'unidad', 'minimum_stock' => 0,
                'presentations' => [['name' => 'unidad', 'factor_to_base' => 1, 'price_cents' => 1000, 'is_base' => true]],
            ])->assertCreated()->json('data');
        }
        $lines = [];
        foreach ($products as $product) {
            $presentation = \App\Models\ProductPresentation::query()->where('product_id', $product['id'])->firstOrFail();
            $lines[] = ['product_id' => $product['id'], 'presentation_id' => $presentation->id, 'quantity' => '2', 'unit_cost_cents' => 650];
        }
        $this->postJson('/api/v1/purchase-orders', [
            'operation_key' => 'quality-multi-purchase-001', 'supplier_id' => $supplier['id'], 'lines' => $lines,
        ])->assertCreated()->assertJsonCount(2, 'data.lines');
    }

    public function test_only_management_can_approve_sale_discounts_and_discount_is_audited(): void
    {
        $manager = User::factory()->create(['role' => 'manager']);
        $this->actingAs($manager, 'web');
        $warehouse = Warehouse::query()->where('code', 'MAIN')->firstOrFail();
        $product = $this->postJson('/api/v1/products', [
            'sku' => 'QUALITY-DISCOUNT-001', 'name' => 'Producto con descuento', 'base_unit' => 'unidad', 'minimum_stock' => 0,
            'presentations' => [['name' => 'unidad', 'factor_to_base' => 1, 'price_cents' => 10000, 'is_base' => true]],
        ])->assertCreated()->json('data');
        $presentationId = $product['presentations'][0]['id'];
        $this->postJson('/api/v1/inventory/movements', [
            'product_id' => $product['id'], 'warehouse_id' => $warehouse->id, 'presentation_id' => $presentationId,
            'type' => 'opening', 'quantity' => 5, 'unit_cost_cents' => 5000, 'reason' => 'Apertura', 'operation_key' => 'discount-stock-001',
        ])->assertCreated();
        $session = $this->postJson('/api/v1/cash/sessions', ['warehouse_id' => $warehouse->id, 'opening_cents' => 0])->assertCreated()->json('data');
        $salePayload = [
            'operation_key' => 'discount-sale-cashier-001', 'cash_session_id' => $session['id'],
            'lines' => [['product_id' => $product['id'], 'presentation_id' => $presentationId, 'quantity' => 1, 'discount_cents' => 500]],
            'payments' => [['method' => 'cash', 'amount_cents' => 9500]],
        ];
        $this->assertSame('manager', $manager->fresh()->role);
        $sale = $this->postJson('/api/v1/sales', array_replace($salePayload, ['operation_key' => 'discount-sale-manager-001']))
            ->assertCreated()->assertJsonPath('data.subtotal_cents', 10000)->assertJsonPath('data.total_cents', 9500)
            ->assertJsonPath('data.lines.0.subtotal_cents', 10000)->assertJsonPath('data.lines.0.discount_cents', 500)
            ->assertJsonPath('data.lines.0.line_total_cents', 9500)->assertJsonPath('data.lines.0.discount_approved_by_user_id', $manager->id)->json('data');
        $this->assertSame(9500, (int) $sale['paid_cents']);
        $this->postJson('/api/v1/sales', array_replace($salePayload, [
            'operation_key' => 'discount-sale-too-large-001', 'lines' => [['product_id' => $product['id'], 'presentation_id' => $presentationId, 'quantity' => 1, 'discount_cents' => 10001]],
        ]))->assertUnprocessable()->assertJsonValidationErrors('lines');
    }

    public function test_cashier_cannot_submit_sale_discount(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier, 'web');
        $warehouse = Warehouse::query()->where('code', 'MAIN')->firstOrFail();
        $product = $this->postJson('/api/v1/products', [
            'sku' => 'QUALITY-DISCOUNT-002', 'name' => 'Producto sin descuento autorizado', 'base_unit' => 'unidad', 'minimum_stock' => 0,
            'presentations' => [['name' => 'unidad', 'factor_to_base' => 1, 'price_cents' => 10000, 'is_base' => true]],
        ])->assertCreated()->json('data');
        $presentationId = $product['presentations'][0]['id'];
        $this->postJson('/api/v1/inventory/movements', [
            'product_id' => $product['id'], 'warehouse_id' => $warehouse->id, 'presentation_id' => $presentationId,
            'type' => 'opening', 'quantity' => 5, 'unit_cost_cents' => 5000, 'reason' => 'Apertura', 'operation_key' => 'discount-stock-002',
        ])->assertCreated();
        $session = $this->postJson('/api/v1/cash/sessions', ['warehouse_id' => $warehouse->id, 'opening_cents' => 0])->assertCreated()->json('data');
        $this->postJson('/api/v1/sales', [
            'operation_key' => 'discount-sale-cashier-001', 'cash_session_id' => $session['id'],
            'lines' => [['product_id' => $product['id'], 'presentation_id' => $presentationId, 'quantity' => 1, 'discount_cents' => 500]],
            'payments' => [['method' => 'cash', 'amount_cents' => 9500]],
        ])->assertUnprocessable()->assertJsonValidationErrors('lines');
    }

    public function test_management_return_restock_partial_quantity_and_cancel_a_sale(): void
    {
        $manager = User::factory()->create(['role' => 'manager']);
        $this->actingAs($manager, 'web');
        $warehouse = Warehouse::query()->where('code', 'MAIN')->firstOrFail();
        $product = $this->postJson('/api/v1/products', [
            'sku' => 'QUALITY-RETURN-001', 'name' => 'Producto con devolución', 'base_unit' => 'unidad', 'minimum_stock' => 0,
            'presentations' => [['name' => 'unidad', 'factor_to_base' => 1, 'price_cents' => 10000, 'is_base' => true]],
        ])->assertCreated()->json('data');
        $presentationId = $product['presentations'][0]['id'];
        $this->postJson('/api/v1/inventory/movements', [
            'product_id' => $product['id'], 'warehouse_id' => $warehouse->id, 'presentation_id' => $presentationId,
            'type' => 'opening', 'quantity' => 5, 'unit_cost_cents' => 5000, 'reason' => 'Apertura', 'operation_key' => 'return-stock-001',
        ])->assertCreated();
        $session = $this->postJson('/api/v1/cash/sessions', ['warehouse_id' => $warehouse->id, 'opening_cents' => 0])->assertCreated()->json('data');
        $sale = $this->postJson('/api/v1/sales', [
            'operation_key' => 'return-sale-001', 'cash_session_id' => $session['id'],
            'lines' => [['product_id' => $product['id'], 'presentation_id' => $presentationId, 'quantity' => 2]],
            'payments' => [['method' => 'cash', 'amount_cents' => 20000]],
        ])->assertCreated()->json('data');
        $returned = $this->postJson('/api/v1/sales/'.$sale['id'].'/returns', [
            'operation_key' => 'return-partial-001', 'reason' => 'Cliente devuelve una unidad', 'refund_method' => 'cash', 'cash_session_id' => $session['id'],
            'lines' => [['sale_line_id' => $sale['lines'][0]['id'], 'quantity' => 1]],
        ])->assertCreated()->assertJsonPath('data.amount_cents', 10000)->assertJsonPath('data.refund_cents', 10000)->json('data');
        $this->assertSame(10000, (int) Sale::query()->findOrFail($sale['id'])->total_cents);
        $this->assertSame(10000, (int) CashSession::query()->findOrFail($session['id'])->expected_cents);
        $this->assertSame('4.000000', StockBalance::query()->where('product_id', $product['id'])->value('physical_quantity'));
        $this->postJson('/api/v1/sales/'.$sale['id'].'/cancel', [
            'operation_key' => 'return-cancel-001', 'reason' => 'Anulación total autorizada', 'refund_method' => 'cash', 'cash_session_id' => $session['id'],
        ])->assertCreated()->assertJsonPath('data.amount_cents', 10000);
        $this->assertSame('cancelled', Sale::query()->findOrFail($sale['id'])->status);
        $this->assertSame('5.000000', StockBalance::query()->where('product_id', $product['id'])->value('physical_quantity'));
        $this->getJson('/api/v1/reports/overview?from='.now()->toDateString().'&to='.now()->toDateString().'&warehouse_id='.$warehouse->id)
            ->assertOk()->assertJsonPath('data.sales.total_cents', 0)->assertJsonPath('data.finance.cost_of_goods_cents', 0)
            ->assertJsonPath('data.finance.cashflow_net_cents', 0);
    }

    public function test_sales_receipts_transfers_and_delivery_use_the_selected_warehouse_locations(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'manager']), 'web');
        $warehouse = Warehouse::query()->where('code', 'MAIN')->firstOrFail();
        $sourceLocation = $this->postJson('/api/v1/warehouses/'.$warehouse->id.'/locations', ['code' => 'FLOW-A', 'name' => 'Zona A'])
            ->assertCreated()->json('data');
        $destination = $this->postJson('/api/v1/warehouses', ['code' => 'FLOW-B', 'name' => 'Almacén B', 'location_name' => 'Zona general'])
            ->assertCreated()->json('data');
        $destinationLocation = $this->postJson('/api/v1/warehouses/'.$destination['id'].'/locations', ['code' => 'FLOW-B2', 'name' => 'Zona B2'])
            ->assertCreated()->json('data');
        $product = $this->postJson('/api/v1/products', [
            'sku' => 'QUALITY-LOC-FLOW-001', 'name' => 'Producto por ubicación', 'base_unit' => 'unidad', 'minimum_stock' => 0,
            'presentations' => [['name' => 'unidad', 'factor_to_base' => 1, 'price_cents' => 1000, 'is_base' => true]],
        ])->assertCreated()->json('data');
        $presentationId = $product['presentations'][0]['id'];
        $this->postJson('/api/v1/inventory/movements', [
            'product_id' => $product['id'], 'warehouse_id' => $warehouse->id, 'location_id' => $sourceLocation['id'], 'presentation_id' => $presentationId,
            'type' => 'opening', 'quantity' => 10, 'unit_cost_cents' => 500, 'reason' => 'Apertura ubicación A', 'operation_key' => 'location-flow-open-001',
        ])->assertCreated();
        $session = $this->postJson('/api/v1/cash/sessions', ['warehouse_id' => $warehouse->id, 'opening_cents' => 10000])->assertCreated()->json('data');
        $sale = $this->postJson('/api/v1/sales', [
            'operation_key' => 'location-flow-sale-001', 'cash_session_id' => $session['id'], 'warehouse_location_id' => $sourceLocation['id'],
            'lines' => [['product_id' => $product['id'], 'presentation_id' => $presentationId, 'quantity' => 1]],
            'payments' => [['method' => 'cash', 'amount_cents' => 1000]],
        ])->assertCreated()->assertJsonPath('data.warehouse_location_id', $sourceLocation['id'])->json('data');

        $supplier = $this->postJson('/api/v1/suppliers', ['name' => 'Proveedor de ubicación'])->assertCreated()->json('data');
        $order = $this->postJson('/api/v1/purchase-orders', [
            'operation_key' => 'location-flow-order-001', 'supplier_id' => $supplier['id'],
            'lines' => [['product_id' => $product['id'], 'presentation_id' => $presentationId, 'quantity' => 2, 'unit_cost_cents' => 600]],
        ])->assertCreated()->json('data');
        $this->postJson('/api/v1/purchase-orders/'.$order['id'].'/receipts', [
            'receipt_key' => 'location-flow-receipt-001', 'warehouse_id' => $warehouse->id, 'warehouse_location_id' => $sourceLocation['id'],
            'lines' => [['purchase_order_line_id' => $order['lines'][0]['id'], 'quantity' => 2]],
        ])->assertCreated();
        $this->assertSame($sourceLocation['id'], (int) DB::table('inventory_movements')->where('operation_key', 'pr:location-flow-receipt-001:'.$order['lines'][0]['id'])->value('warehouse_location_id'));

        $transfer = $this->postJson('/api/v1/inventory/transfers', [
            'operation_key' => 'location-flow-transfer-001', 'source_warehouse_id' => $warehouse->id, 'source_location_id' => $sourceLocation['id'],
            'destination_warehouse_id' => $destination['id'], 'destination_location_id' => $destinationLocation['id'],
            'lines' => [['product_id' => $product['id'], 'presentation_id' => $presentationId, 'quantity' => 2]],
        ])->assertCreated()->json('data');
        $this->postJson('/api/v1/inventory/transfers/'.$transfer['id'].'/receipts', [
            'operation_key' => 'location-flow-transfer-receipt-001', 'lines' => [['transfer_line_id' => $transfer['lines'][0]['id'], 'quantity' => 2]],
        ])->assertOk();
        $this->assertSame($destinationLocation['id'], (int) DB::table('inventory_movements')->where('operation_key', 'transfer:location-flow-transfer-receipt-001:'.$transfer['lines'][0]['id'])->value('warehouse_location_id'));

        $customer = $this->postJson('/api/v1/customers', ['name' => 'Cliente por ubicación', 'credit_limit_cents' => 50000])->assertCreated()->json('data');
        $delivery = $this->postJson('/api/v1/delivery-orders', [
            'operation_key' => 'location-flow-delivery-order-001', 'warehouse_id' => $warehouse->id, 'warehouse_location_id' => $sourceLocation['id'],
            'customer_id' => $customer['id'], 'source' => 'in_person',
            'lines' => [['product_id' => $product['id'], 'presentation_id' => $presentationId, 'quantity' => 1]],
        ])->assertCreated()->json('data');
        $this->postJson('/api/v1/delivery-orders/'.$delivery['id'].'/pick', [
            'operation_key' => 'location-flow-delivery-pick-001', 'lines' => [['delivery_order_line_id' => $delivery['lines'][0]['id'], 'quantity' => 1]],
        ])->assertOk();
        $shipment = $this->postJson('/api/v1/delivery-orders/'.$delivery['id'].'/deliver', [
            'shipment_key' => 'location-flow-delivery-ship-001', 'lines' => [['delivery_order_line_id' => $delivery['lines'][0]['id'], 'quantity' => 1]],
        ])->assertCreated()->json('data');
        $this->assertSame($sourceLocation['id'], (int) Sale::query()->findOrFail($shipment['sale_id'])->warehouse_location_id);
        $this->assertSame($sourceLocation['id'], (int) $sale['warehouse_location_id']);
    }

    public function test_foreign_currency_purchase_uses_dated_rate_for_inventory_and_invoice_reconciliation(): void
    {
        $this->actingAs(User::factory()->create(), 'web');
        $warehouse = Warehouse::query()->where('code', 'MAIN')->firstOrFail();
        $supplier = $this->postJson('/api/v1/suppliers', ['name' => 'Proveedor en dólares'])->assertCreated()->json('data');
        $product = $this->postJson('/api/v1/products', [
            'sku' => 'QUALITY-FX-001', 'name' => 'Artículo importado', 'base_unit' => 'unidad', 'minimum_stock' => 0,
            'presentations' => [['name' => 'unidad', 'factor_to_base' => 1, 'price_cents' => 9000, 'is_base' => true]],
        ])->assertCreated()->json('data');
        $payload = [
            'operation_key' => 'quality-fx-purchase-001', 'supplier_id' => $supplier['id'], 'currency_code' => 'USD',
            'exchange_rate' => '3.75000000', 'exchange_rate_date' => now()->toDateString(), 'landed_cost_cents' => 200,
            'lines' => [['product_id' => $product['id'], 'presentation_id' => $product['presentations'][0]['id'], 'quantity' => 2, 'unit_cost_cents' => 1000]],
        ];
        $this->postJson('/api/v1/purchase-orders', array_diff_key($payload, ['exchange_rate_date' => true]))
            ->assertUnprocessable()->assertJsonValidationErrors('exchange_rate_date');
        $order = $this->postJson('/api/v1/purchase-orders', $payload)->assertCreated()
            ->assertJsonPath('data.currency_code', 'USD')->assertJsonPath('data.exchange_rate', '3.75000000')
            ->assertJsonPath('data.document_landed_cost_cents', 200)->assertJsonPath('data.landed_cost_cents', 750)
            ->assertJsonPath('data.lines.0.document_unit_cost_cents', 1000)
            ->assertJsonPath('data.lines.0.unit_cost_cents', 3750)->json('data');
        $this->postJson('/api/v1/purchase-orders/'.$order['id'].'/receipts', [
            'receipt_key' => 'quality-fx-receipt-001', 'warehouse_id' => $warehouse->id,
            'lines' => [['purchase_order_line_id' => $order['lines'][0]['id'], 'quantity' => 2]],
        ])->assertCreated();
        $this->assertSame('4125.000000', StockBalance::query()->where('product_id', $product['id'])->value('average_unit_cost_cents'));
        $this->postJson('/api/v1/purchase-orders/'.$order['id'].'/invoices', [
            'document_type' => 'invoice', 'series' => 'FX01', 'number' => '1', 'issue_date' => now()->toDateString(),
            'currency_code' => 'USD', 'document_total_cents' => 2200, 'exchange_rate' => '3.75',
            'taxable_base_cents' => 1864, 'igv_cents' => 336, 'exempt_amount_cents' => 0,
            'unaffected_amount_cents' => 0, 'other_taxes_cents' => 0,
        ])->assertCreated()->assertJsonPath('data.total_cents', 8250)->assertJsonPath('data.variance_cents', 0)
            ->assertJsonPath('data.reconciliation_status', 'matched');
    }

    public function test_landed_costs_are_prorated_across_partial_receipts_without_rounding_them_away(): void
    {
        $this->actingAs(User::factory()->create(), 'web');
        $warehouse = Warehouse::query()->where('code', 'MAIN')->firstOrFail();
        $supplier = $this->postJson('/api/v1/suppliers', ['name' => 'Proveedor con flete'])->assertCreated()->json('data');
        $product = $this->postJson('/api/v1/products', [
            'sku' => 'SKU-LANDED-001', 'name' => 'Producto con gasto prorrateado', 'base_unit' => 'unidad',
            'minimum_stock' => 0, 'presentations' => [['name' => 'unidad', 'factor_to_base' => 1, 'price_cents' => 1500, 'is_base' => true]],
        ])->assertCreated()->json('data');
        $presentation = \App\Models\ProductPresentation::query()->where('product_id', $product['id'])->firstOrFail();
        $order = $this->postJson('/api/v1/purchase-orders', [
            'operation_key' => 'quality-landed-order-001', 'supplier_id' => $supplier['id'], 'landed_cost_cents' => 400,
            'lines' => [['product_id' => $product['id'], 'presentation_id' => $presentation->id, 'quantity' => '4', 'unit_cost_cents' => 1000]],
        ])->assertCreated()->json('data');

        $this->postJson('/api/v1/purchase-orders/'.$order['id'].'/receipts', [
            'receipt_key' => 'quality-landed-receipt-001', 'warehouse_id' => $warehouse->id,
            'lines' => [['purchase_order_line_id' => $order['lines'][0]['id'], 'quantity' => '2']],
        ])->assertCreated();
        $this->assertSame(200, (int) PurchaseOrder::query()->findOrFail($order['id'])->landed_cost_allocated_cents);
        $this->assertSame(1100, (int) StockBalance::query()->where('product_id', $product['id'])->value('average_unit_cost_cents'));

        $this->postJson('/api/v1/purchase-orders/'.$order['id'].'/receipts', [
            'receipt_key' => 'quality-landed-receipt-002', 'warehouse_id' => $warehouse->id,
            'lines' => [['purchase_order_line_id' => $order['lines'][0]['id'], 'quantity' => '2']],
        ])->assertCreated()->assertJsonPath('order.status', 'received');
        $this->assertSame(400, (int) PurchaseOrder::query()->findOrFail($order['id'])->landed_cost_allocated_cents);
        $this->assertSame(400, (int) DB::table('purchase_receipt_lines')->where('purchase_order_line_id', $order['lines'][0]['id'])->sum('landed_cost_cents'));
        $this->assertSame('1100.000000', StockBalance::query()->where('product_id', $product['id'])->value('average_unit_cost_cents'));
        $session = $this->postJson('/api/v1/cash/sessions', ['warehouse_id' => $warehouse->id, 'opening_cents' => 0])->assertCreated()->json('data');
        $this->postJson('/api/v1/sales', [
            'operation_key' => 'quality-landed-sale-001', 'cash_session_id' => $session['id'],
            'lines' => [['product_id' => $product['id'], 'presentation_id' => $presentation->id, 'quantity' => '1']],
            'payments' => [['method' => 'cash', 'amount_cents' => 1500]],
        ])->assertCreated();
        $this->getJson('/api/v1/reports/overview?from='.now()->toDateString().'&to='.now()->toDateString().'&warehouse_id='.$warehouse->id)
            ->assertOk()->assertJsonPath('data.finance.cost_of_goods_cents', 1100)
            ->assertJsonPath('data.finance.gross_profit_cents', 400);
    }

    public function test_product_purchase_cash_credit_delivery_and_report_work_end_to_end(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'web');
        $warehouse = Warehouse::query()->where('code', 'MAIN')->firstOrFail();

        // A. Products and presentations.
        $this->postJson('/api/v1/product-brands', ['name' => 'Lions demo'])->assertCreated();
        $product = $this->postJson('/api/v1/products', [
            'sku' => 'LQ-DEMO-001', 'name' => 'Caja organizadora de muestra', 'brand' => 'Lions demo',
            'base_unit' => 'unidad', 'minimum_stock' => '12', 'allows_fractional_quantity' => false,
            'presentations' => [['name' => 'unidad', 'factor_to_base' => '1', 'price_cents' => 1990, 'is_base' => true]],
        ])->assertCreated()->json('data');
        $this->postJson("/api/v1/products/{$product['id']}/presentations", [
            'name' => 'caja x 12', 'factor_to_base' => '12', 'price_cents' => 19900,
        ])->assertCreated();
        $productModel = Product::query()->findOrFail($product['id']);
        $unit = $productModel->presentations()->where('is_base', true)->firstOrFail();
        $case = $productModel->presentations()->where('name', 'caja x 12')->firstOrFail();
        $secondProduct = $this->postJson('/api/v1/products', [
            'sku' => 'LQ-DEMO-002', 'name' => 'Botella reutilizable de muestra', 'brand' => 'Lions demo',
            'base_unit' => 'unidad', 'minimum_stock' => '0', 'allows_fractional_quantity' => false,
            'presentations' => [['name' => 'unidad', 'factor_to_base' => '1', 'price_cents' => 2500, 'is_base' => true]],
        ])->assertCreated()->json('data');
        $secondProductModel = Product::query()->findOrFail($secondProduct['id']);
        $secondUnit = $secondProductModel->presentations()->where('is_base', true)->firstOrFail();

        // B. Warehouses and inventory: initial balance, conversion, replay and stock guard.
        $this->postJson('/api/v1/inventory/movements', [
            'product_id' => $productModel->id, 'warehouse_id' => $warehouse->id, 'presentation_id' => $case->id,
            'type' => 'opening', 'quantity' => '20', 'unit_cost_cents' => 12000,
            'reason' => 'DEMO: conteo inicial', 'operation_key' => 'quality-opening-001',
        ])->assertCreated();
        $this->postJson('/api/v1/inventory/movements', [
            'product_id' => $productModel->id, 'warehouse_id' => $warehouse->id, 'presentation_id' => $case->id,
            'type' => 'opening', 'quantity' => '20', 'unit_cost_cents' => 12000,
            'reason' => 'DEMO: conteo inicial', 'operation_key' => 'quality-opening-001',
        ])->assertCreated();
        $this->assertSame(1, $productModel->movements()->count());
        $this->postJson('/api/v1/inventory/movements', [
            'product_id' => $secondProductModel->id, 'warehouse_id' => $warehouse->id, 'presentation_id' => $secondUnit->id,
            'type' => 'opening', 'quantity' => '10', 'reason' => 'DEMO: stock inicial', 'operation_key' => 'quality-opening-002',
        ])->assertCreated();
        $this->postJson('/api/v1/inventory/movements', [
            'product_id' => $productModel->id, 'warehouse_id' => $warehouse->id, 'presentation_id' => $case->id,
            'type' => 'adjustment_out', 'quantity' => '21', 'reason' => 'DEMO: prueba de límite', 'operation_key' => 'quality-overdraw-001',
        ])->assertUnprocessable();
        $this->assertDatabaseHas('stock_balances', ['product_id' => $productModel->id, 'physical_quantity' => '240.000000']);

        // C. Supplier, purchase order, partial receipt and supplier payable.
        $supplier = $this->postJson('/api/v1/suppliers', [
            'name' => 'DEMO · Proveedor de artículos para el hogar', 'email' => 'compras-demo@example.test',
            'address' => 'Registro de demostración',
        ])->assertCreated()->json('data');
        $order = $this->postJson('/api/v1/purchase-orders', [
            'operation_key' => 'quality-purchase-001', 'supplier_id' => $supplier['id'],
            'notes' => 'DEMO: recepción parcial', 'lines' => [[
                'product_id' => $productModel->id, 'presentation_id' => $case->id,
                'quantity' => '10', 'unit_cost_cents' => 13200,
            ]],
        ])->assertCreated()->json('data');
        $lineId = $order['lines'][0]['id'];
        $this->postJson("/api/v1/purchase-orders/{$order['id']}/receipts", [
            'receipt_key' => 'quality-receipt-001', 'warehouse_id' => $warehouse->id,
            'supplier_document_reference' => 'DEMO-REMITO-001',
            'lines' => [['purchase_order_line_id' => $lineId, 'quantity' => '5']],
        ])->assertCreated()->assertJsonPath('order.status', 'partially_received');
        $this->postJson("/api/v1/purchase-orders/{$order['id']}/receipts", [
            'receipt_key' => 'quality-overreceipt-001', 'warehouse_id' => $warehouse->id,
            'lines' => [['purchase_order_line_id' => $lineId, 'quantity' => '6']],
        ])->assertUnprocessable();
        $this->postJson("/api/v1/purchase-orders/{$order['id']}/invoices", [
            'document_type' => 'invoice', 'series' => 'DEMO01', 'number' => '1001',
            'issue_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(),
            'total_cents' => 132000,
            'taxable_base_cents' => 111864, 'igv_cents' => 20136, 'exempt_amount_cents' => 0,
            'unaffected_amount_cents' => 0, 'other_taxes_cents' => 0,
        ])->assertCreated()->assertJsonPath('data.status', 'open');
        $this->assertSame('partially_received', PurchaseOrder::query()->findOrFail($order['id'])->status);
        $this->assertSame('open', SupplierInvoice::query()->firstOrFail()->status);

        // D. Cash and mixed tender sale; E. customer credit and partial collection.
        $session = $this->postJson('/api/v1/cash/sessions', [
            'warehouse_id' => $warehouse->id, 'opening_cents' => 5000,
        ])->assertCreated()->json('data');
        $this->postJson('/api/v1/cash/sessions', [
            'warehouse_id' => $warehouse->id, 'opening_cents' => 5000,
        ])->assertConflict();
        $customer = $this->postJson('/api/v1/customers', [
            'name' => 'DEMO · Bodega San Martín', 'tax_id' => null,
            'address' => 'Cliente de demostración', 'credit_limit_cents' => 150000,
        ])->assertCreated()->json('data');
        $sale = $this->postJson('/api/v1/sales', [
            'operation_key' => 'quality-sale-001', 'cash_session_id' => $session['id'], 'customer_id' => $customer['id'],
            'lines' => [
                ['product_id' => $productModel->id, 'presentation_id' => $case->id, 'quantity' => '1'],
                ['product_id' => $secondProductModel->id, 'presentation_id' => $secondUnit->id, 'quantity' => '2'],
            ],
            'payments' => [
                ['method' => 'cash', 'amount_cents' => 5000],
                ['method' => 'transfer', 'amount_cents' => 4900, 'reference' => 'DEMO-TRANSFER-01'],
            ],
        ])->assertCreated()->json('data');
        $this->assertCount(2, $sale['lines']);
        $this->assertSame(24900, $sale['total_cents']);
        $this->assertSame(9900, $sale['paid_cents']);
        $this->postJson("/api/v1/sales/{$sale['id']}/payments", [
            'operation_key' => 'quality-collection-001', 'cash_session_id' => $session['id'],
            'method' => 'cash', 'amount_cents' => 5000,
        ])->assertOk()->assertJsonPath('data.paid_cents', 14900);
        $this->postJson("/api/v1/sales/{$sale['id']}/payments", [
            'operation_key' => 'quality-overcollection-001', 'cash_session_id' => $session['id'],
            'method' => 'cash', 'amount_cents' => 10001,
        ])->assertUnprocessable();
        $this->postJson("/api/v1/sales/{$sale['id']}/payments", [
            'operation_key' => 'quality-collection-001', 'cash_session_id' => $session['id'],
            'method' => 'cash', 'amount_cents' => 5000,
        ])->assertOk()->assertJsonPath('data.paid_cents', 14900);
        $this->getJson("/api/v1/customers/{$customer['id']}/account")
            ->assertOk()->assertJsonPath('data.outstanding_cents', 10000);

        // F. Reserve, pick, deliver once, and verify the physical balance decreases only once.
        $delivery = $this->postJson('/api/v1/delivery-orders', [
            'operation_key' => 'quality-delivery-order-001', 'warehouse_id' => $warehouse->id,
            'customer_id' => $customer['id'], 'notes' => 'DEMO: pedido presencial',
            'lines' => [['product_id' => $productModel->id, 'presentation_id' => $case->id, 'quantity' => '2']],
        ])->assertCreated()->json('data');
        $deliveryLineId = $delivery['lines'][0]['id'];
        $this->postJson("/api/v1/delivery-orders/{$delivery['id']}/pick", [
            'operation_key' => 'quality-pick-001', 'lines' => [['delivery_order_line_id' => $deliveryLineId, 'quantity' => '1']],
        ])->assertOk()->assertJsonPath('data.status', 'partially_picked');
        $shipment = $this->postJson("/api/v1/delivery-orders/{$delivery['id']}/deliver", [
            'shipment_key' => 'quality-shipment-001', 'lines' => [['delivery_order_line_id' => $deliveryLineId, 'quantity' => '1']],
        ])->assertCreated()->json('data');
        $this->assertNotNull($shipment['sale']);
        $balance = StockBalance::query()->where('product_id', $productModel->id)->firstOrFail();
        $this->assertSame('276.000000', $balance->physical_quantity);
        $this->assertSame('12.000000', $balance->reserved_quantity);
        $this->postJson("/api/v1/delivery-orders/{$delivery['id']}/deliver", [
            'shipment_key' => 'quality-shipment-001',
            'lines' => [['delivery_order_line_id' => $deliveryLineId, 'quantity' => '1']],
        ])->assertCreated();
        $this->assertSame('276.000000', $balance->fresh()->physical_quantity);

        // Cancellation releases the remaining reservation without moving physical stock.
        $cancelOrder = $this->postJson('/api/v1/delivery-orders', [
            'operation_key' => 'quality-cancel-order-001', 'warehouse_id' => $warehouse->id,
            'customer_id' => $customer['id'], 'lines' => [['product_id' => $productModel->id, 'presentation_id' => $case->id, 'quantity' => '1']],
        ])->assertCreated()->json('data');
        $this->postJson("/api/v1/delivery-orders/{$cancelOrder['id']}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame('276.000000', $balance->fresh()->physical_quantity);

        // G/I. Finance and management outputs are explicitly operational summaries.
        $this->postJson("/api/v1/cash/sessions/{$session['id']}/close", ['counted_cents' => 15500])->assertOk();
        $this->getJson('/api/v1/reports/overview?from='.now()->toDateString().'&to='.now()->toDateString().'&warehouse_id='.$warehouse->id)
            ->assertOk()->assertJsonPath('data.sales.count', 2)
            ->assertJsonPath('data.sales.total_cents', 44800)
            ->assertJsonPath('data.cash.closed_variance_cents', 500)
            ->assertJsonPath('data.inventory.low_stock_count', 0);
    }

    public function test_all_business_writes_require_authentication_and_reject_over_collection(): void
    {
        $warehouse = Warehouse::query()->where('code', 'MAIN')->firstOrFail();
        $this->postJson('/api/v1/cash/sessions', ['warehouse_id' => $warehouse->id, 'opening_cents' => 0])
            ->assertUnauthorized();

        $user = User::factory()->create();
        $this->actingAs($user, 'web');
        $this->postJson('/api/v1/cash/sessions', ['warehouse_id' => $warehouse->id, 'opening_cents' => 0])->assertCreated();
    }

    public function test_customer_advance_is_audited_applied_once_and_sale_due_date_is_kept(): void
    {
        $this->actingAs(User::factory()->create(), 'web');
        $warehouse = Warehouse::query()->where('code', 'MAIN')->firstOrFail();
        $customer = $this->postJson('/api/v1/customers', [
            'name' => 'Cliente con adelanto', 'credit_limit_cents' => 5000,
        ])->assertCreated()->json('data');
        $product = $this->postJson('/api/v1/products', [
            'sku' => 'QUALITY-ADVANCE-001', 'name' => 'Producto adelanto', 'base_unit' => 'unidad', 'minimum_stock' => 0,
            'presentations' => [['name' => 'unidad', 'factor_to_base' => 1, 'price_cents' => 1000, 'is_base' => true]],
        ])->assertCreated()->json('data');
        $this->postJson('/api/v1/inventory/movements', [
            'product_id' => $product['id'], 'warehouse_id' => $warehouse->id, 'presentation_id' => $product['presentations'][0]['id'],
            'type' => 'opening', 'quantity' => 10, 'reason' => 'Stock para adelanto', 'operation_key' => 'quality-advance-stock',
        ])->assertCreated();
        $session = $this->postJson('/api/v1/cash/sessions', ['warehouse_id' => $warehouse->id, 'opening_cents' => 0])->assertCreated()->json('data');
        $sale = $this->postJson('/api/v1/sales', [
            'operation_key' => 'quality-advance-sale', 'cash_session_id' => $session['id'], 'customer_id' => $customer['id'],
            'due_date' => now()->addDays(14)->toDateString(),
            'lines' => [['product_id' => $product['id'], 'presentation_id' => $product['presentations'][0]['id'], 'quantity' => 1]], 'payments' => [],
        ])->assertCreated()->json('data');
        $this->assertSame(now()->addDays(14)->toDateString(), $sale['due_date']);
        $this->postJson("/api/v1/customers/{$customer['id']}/advances", [
            'operation_key' => 'quality-advance-deposit', 'cash_session_id' => $session['id'], 'method' => 'cash', 'amount_cents' => 600,
        ])->assertCreated();
        $this->postJson("/api/v1/customers/{$customer['id']}/advances/apply", [
            'operation_key' => 'quality-advance-apply', 'sale_id' => $sale['id'], 'amount_cents' => 400,
        ])->assertOk()->assertJsonPath('data.paid_cents', 400);
        $this->postJson("/api/v1/customers/{$customer['id']}/advances/apply", [
            'operation_key' => 'quality-advance-apply', 'sale_id' => $sale['id'], 'amount_cents' => 400,
        ])->assertOk()->assertJsonPath('data.paid_cents', 400);
        $this->getJson("/api/v1/customers/{$customer['id']}/account")
            ->assertOk()->assertJsonPath('data.advance_balance_cents', 200)->assertJsonPath('data.outstanding_cents', 600);
        $this->assertSame(600, CashSession::query()->findOrFail($session['id'])->expected_cents);
    }

    public function test_delivery_order_records_multiple_lines_source_address_and_responsible(): void
    {
        $this->actingAs(User::factory()->create(), 'web');
        $warehouse = Warehouse::query()->where('code', 'MAIN')->firstOrFail();
        $customer = $this->postJson('/api/v1/customers', ['name' => 'Cliente de despacho', 'credit_limit_cents' => 0])->assertCreated()->json('data');
        $created = [];
        foreach ([['QUALITY-ORDER-A', 'Caja A'], ['QUALITY-ORDER-B', 'Caja B']] as [$sku, $name]) {
            $product = $this->postJson('/api/v1/products', [
                'sku' => $sku, 'name' => $name, 'base_unit' => 'unidad', 'minimum_stock' => 0,
                'presentations' => [['name' => 'unidad', 'factor_to_base' => 1, 'price_cents' => 100, 'is_base' => true]],
            ])->assertCreated()->json('data');
            $this->postJson('/api/v1/inventory/movements', [
                'product_id' => $product['id'], 'warehouse_id' => $warehouse->id, 'presentation_id' => $product['presentations'][0]['id'],
                'type' => 'opening', 'quantity' => 5, 'reason' => 'Stock para pedido', 'operation_key' => 'stock-'.$sku,
            ])->assertCreated();
            $created[] = ['product_id' => $product['id'], 'presentation_id' => $product['presentations'][0]['id'], 'quantity' => 2];
        }
        $order = $this->postJson('/api/v1/delivery-orders', [
            'operation_key' => 'quality-multiline-order', 'warehouse_id' => $warehouse->id, 'customer_id' => $customer['id'],
            'source' => 'whatsapp', 'delivery_address' => 'Av. Ejemplo 123', 'delivery_contact' => '999111222',
            'dispatch_responsible' => 'Chofer de prueba', 'lines' => $created,
        ])->assertCreated()->assertJsonPath('data.source', 'whatsapp')->assertJsonPath('data.delivery_address', 'Av. Ejemplo 123')
            ->assertJsonPath('data.dispatch_responsible', 'Chofer de prueba')->json('data');
        $this->assertCount(2, $order['lines']);
        $this->assertSame('2.000000', StockBalance::query()->where('product_id', $created[0]['product_id'])->value('reserved_quantity'));
        $this->assertSame('2.000000', StockBalance::query()->where('product_id', $created[1]['product_id'])->value('reserved_quantity'));
    }

    public function test_catalog_categories_wholesale_prices_and_presentation_history_are_controlled(): void
    {
        $this->actingAs(User::factory()->create(), 'web');
        $warehouse = Warehouse::query()->where('code', 'MAIN')->firstOrFail();
        $category = $this->postJson('/api/v1/product-categories', ['name' => 'Hogar'])->assertCreated()->json('data');
        $this->postJson('/api/v1/product-brands', ['name' => 'Lions'])->assertCreated();
        $emptyCategory = $this->postJson('/api/v1/product-categories', ['name' => 'Sin productos'])
            ->assertCreated()->json('data');
        $this->getJson('/api/v1/product-categories')->assertOk()
            ->assertJsonFragment(['id' => $emptyCategory['id'], 'name' => 'Sin productos', 'is_active' => true]);
        $this->patchJson('/api/v1/product-categories/'.$emptyCategory['id'], ['is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false);
        $product = $this->postJson('/api/v1/products', [
            'sku' => 'QUALITY-PRICE-001', 'name' => 'Contenedor', 'base_unit' => 'unidad', 'minimum_stock' => 0,
            'category_id' => $category['id'], 'presentations' => [[
                'name' => 'unidad', 'factor_to_base' => 1, 'price_cents' => 1000,
                'wholesale_price_cents' => 750, 'is_base' => true,
            ]],
        ])->assertCreated()->json('data');
        $this->patchJson('/api/v1/product-categories/'.$category['id'], ['name' => 'Organización'])
            ->assertOk()->assertJsonPath('data.name', 'Organización');
        $this->patchJson('/api/v1/products/'.$product['id'], [
            'name' => 'Contenedor plástico', 'brand' => 'Lions', 'barcode' => '750000000001',
            'category_id' => $category['id'], 'minimum_stock' => 4,
        ])->assertOk()->assertJsonPath('data.name', 'Contenedor plástico');
        $presentation = Product::query()->findOrFail($product['id'])->presentations()->firstOrFail();
        $this->postJson('/api/v1/inventory/movements', [
            'product_id' => $product['id'], 'warehouse_id' => $warehouse->id, 'presentation_id' => $presentation->id,
            'type' => 'opening', 'quantity' => 10, 'reason' => 'Stock inicial de prueba', 'operation_key' => 'quality-price-stock',
        ])->assertCreated();
        $session = $this->postJson('/api/v1/cash/sessions', ['warehouse_id' => $warehouse->id, 'opening_cents' => 0])->assertCreated()->json('data');
        $sale = $this->postJson('/api/v1/sales', [
            'operation_key' => 'quality-wholesale-sale', 'cash_session_id' => $session['id'], 'price_list' => 'wholesale',
            'lines' => [['product_id' => $product['id'], 'presentation_id' => $presentation->id, 'quantity' => 2]],
            'payments' => [['method' => 'cash', 'amount_cents' => 1500]],
        ])->assertCreated()->json('data');
        $this->assertSame(1500, $sale['total_cents']);
        $this->assertSame(750, $sale['lines'][0]['unit_price_cents']);
        $this->patchJson("/api/v1/products/{$product['id']}/presentations/{$presentation->id}", ['factor_to_base' => 12])
            ->assertStatus(409);
        $this->patchJson("/api/v1/products/{$product['id']}", ['is_active' => false])->assertOk();
        $this->getJson('/api/v1/products')->assertJsonMissing(['sku' => 'QUALITY-PRICE-001']);
        $this->getJson('/api/v1/products?include_inactive=1')->assertJsonPath('data.0.is_active', false);
    }

    public function test_customer_dni_and_ruc_are_classified_and_format_checked(): void
    {
        $this->actingAs(User::factory()->create(), 'web');
        $base = ['name' => 'Cliente de prueba', 'credit_limit_cents' => 0];

        $this->postJson('/api/v1/customers', $base + ['tax_id_type' => 'dni', 'tax_id' => '1234567'])
            ->assertUnprocessable()->assertJsonValidationErrors('tax_id');
        $this->postJson('/api/v1/customers', $base + ['tax_id_type' => 'ruc', 'tax_id' => '2012345678'])
            ->assertUnprocessable()->assertJsonValidationErrors('tax_id');
        $this->postJson('/api/v1/customers', $base + ['tax_id' => '12345678'])
            ->assertUnprocessable()->assertJsonValidationErrors('tax_id_type');
        $this->postJson('/api/v1/customers', $base + ['name' => 'Persona DNI', 'tax_id_type' => 'dni', 'tax_id' => '12345678'])
            ->assertCreated()->assertJsonPath('data.tax_id_type', 'dni');
        $this->postJson('/api/v1/customers', $base + ['name' => 'Empresa RUC', 'tax_id_type' => 'ruc', 'tax_id' => '20123456789'])
            ->assertCreated()->assertJsonPath('data.tax_id_type', 'ruc');
    }

    public function test_product_brand_and_base_unit_masters_protect_existing_catalog_history(): void
    {
        $this->actingAs(User::factory()->create(), 'web');
        $brand = $this->postJson('/api/v1/product-brands', ['name' => '  Marca temporal  '])
            ->assertCreated()->assertJsonPath('data.name', 'Marca temporal')->json('data');
        $unit = $this->postJson('/api/v1/product-units', ['name' => 'paquete'])
            ->assertCreated()->json('data');
        $product = $this->postJson('/api/v1/products', [
            'sku' => 'QUALITY-MASTER-001', 'name' => 'Producto catálogo', 'brand' => $brand['name'],
            'base_unit' => $unit['name'], 'minimum_stock' => 0,
            'presentations' => [['name' => 'paquete', 'factor_to_base' => 1, 'price_cents' => 100, 'is_base' => true]],
        ])->assertCreated()->json('data');

        $this->patchJson('/api/v1/product-brands/'.$brand['id'], ['name' => 'Marca Lions'])
            ->assertOk()->assertJsonPath('data.name', 'Marca Lions');
        $this->assertSame('Marca Lions', Product::query()->findOrFail($product['id'])->brand);
        $this->patchJson('/api/v1/product-units/'.$unit['id'], ['name' => 'caja'])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->patchJson('/api/v1/product-units/'.$unit['id'], ['is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', 0);
        $this->assertSame('paquete', Product::query()->findOrFail($product['id'])->base_unit);
        $this->postJson('/api/v1/products', [
            'sku' => 'QUALITY-MASTER-002', 'name' => 'No debe aceptar unidad inactiva', 'base_unit' => 'paquete',
            'minimum_stock' => 0,
            'presentations' => [['name' => 'paquete', 'factor_to_base' => 1, 'price_cents' => 100, 'is_base' => true]],
        ])->assertUnprocessable()->assertJsonValidationErrors('base_unit');
        $this->getJson('/api/v1/product-masters')->assertOk()
            ->assertJsonPath('data.brands.0.name', 'Marca Lions');
    }

    public function test_physical_count_and_damage_write_audited_stock_movements(): void
    {
        $this->actingAs(User::factory()->create(), 'web');
        $warehouse = Warehouse::query()->where('code', 'MAIN')->firstOrFail();
        $product = $this->postJson('/api/v1/products', [
            'sku' => 'QUALITY-COUNT-001', 'name' => 'Vaso', 'base_unit' => 'unidad', 'minimum_stock' => 0,
            'presentations' => [['name' => 'unidad', 'factor_to_base' => 1, 'price_cents' => 100, 'is_base' => true]],
        ])->assertCreated()->json('data');
        $presentationId = $product['presentations'][0]['id'];
        $this->postJson('/api/v1/inventory/movements', [
            'product_id' => $product['id'], 'warehouse_id' => $warehouse->id, 'presentation_id' => $presentationId,
            'type' => 'opening', 'quantity' => 10, 'reason' => 'Stock inicial', 'operation_key' => 'quality-count-opening',
        ])->assertCreated();
        $this->postJson('/api/v1/inventory/counts', [
            'warehouse_id' => $warehouse->id, 'product_id' => $product['id'], 'counted_quantity' => 8,
            'reason' => 'Conteo de control', 'operation_key' => 'quality-count-001',
        ])->assertCreated()->assertJsonPath('data.variance_quantity', '-2.000000');
        $this->postJson('/api/v1/inventory/movements', [
            'product_id' => $product['id'], 'warehouse_id' => $warehouse->id, 'presentation_id' => $presentationId,
            'type' => 'damaged', 'quantity' => 1, 'reason' => 'Rotura durante almacenaje', 'operation_key' => 'quality-damaged-001',
        ])->assertCreated()->assertJsonPath('data.type', 'damaged');
        $this->assertSame('7.000000', StockBalance::query()->where('product_id', $product['id'])->value('physical_quantity'));
    }

    public function test_inventory_locations_can_be_created_selected_and_made_default(): void
    {
        $this->actingAs(User::factory()->create(), 'web');
        $warehouse = Warehouse::query()->where('code', 'MAIN')->firstOrFail();
        $location = $this->postJson('/api/v1/warehouses/'.$warehouse->id.'/locations', [
            'code' => 'A-01', 'name' => 'Pasillo A · nivel 1',
        ])->assertCreated()->json('data');
        $product = $this->postJson('/api/v1/products', [
            'sku' => 'QUALITY-LOCATION-001', 'name' => 'Caja ubicada', 'base_unit' => 'unidad', 'minimum_stock' => 0,
            'presentations' => [['name' => 'unidad', 'factor_to_base' => 1, 'price_cents' => 1000, 'is_base' => true]],
        ])->assertCreated()->json('data');
        $this->postJson('/api/v1/inventory/movements', [
            'product_id' => $product['id'], 'warehouse_id' => $warehouse->id, 'location_id' => $location['id'],
            'presentation_id' => $product['presentations'][0]['id'], 'type' => 'opening', 'quantity' => 5,
            'reason' => 'Ubicación inicial de prueba', 'operation_key' => 'quality-location-opening-001',
        ])->assertCreated()->assertJsonPath('data.warehouse_location_id', $location['id']);
        $this->postJson('/api/v1/inventory/counts', [
            'warehouse_id' => $warehouse->id, 'location_id' => $location['id'], 'product_id' => $product['id'],
            'counted_quantity' => 4, 'reason' => 'Conteo de ubicación', 'operation_key' => 'quality-location-count-001',
        ])->assertCreated()->assertJsonPath('data.system_quantity', '5.000000');
        $this->patchJson('/api/v1/warehouses/'.$warehouse->id.'/locations/'.$location['id'], ['is_default' => true])
            ->assertOk()->assertJsonPath('data.is_default', true);
        $this->getJson('/api/v1/warehouses')->assertOk()
            ->assertJsonPath('data.0.location_id', $location['id'])
            ->assertJsonPath('data.0.location_name', 'Pasillo A · nivel 1');
        $this->assertSame('4.000000', StockBalance::query()->where('warehouse_location_id', $location['id'])->where('product_id', $product['id'])->value('physical_quantity'));
    }

    public function test_direct_purchase_and_partial_supplier_invoice_payment_reconcile(): void
    {
        $this->actingAs(User::factory()->create(), 'web');
        $warehouse = Warehouse::query()->where('code', 'MAIN')->firstOrFail();
        $supplier = $this->postJson('/api/v1/suppliers', ['name' => 'Proveedor de prueba'])->assertCreated()->json('data');
        $product = $this->postJson('/api/v1/products', [
            'sku' => 'QUALITY-DIRECT-001', 'name' => 'Canasta', 'base_unit' => 'unidad', 'minimum_stock' => 0,
            'presentations' => [['name' => 'unidad', 'factor_to_base' => 1, 'price_cents' => 1000, 'is_base' => true]],
        ])->assertCreated()->json('data');
        $secondProduct = $this->postJson('/api/v1/products', [
            'sku' => 'QUALITY-DIRECT-002', 'name' => 'Organizador', 'base_unit' => 'unidad', 'minimum_stock' => 0,
            'presentations' => [['name' => 'unidad', 'factor_to_base' => 1, 'price_cents' => 2500, 'is_base' => true]],
        ])->assertCreated()->json('data');
        $purchase = $this->postJson('/api/v1/purchase-orders/direct', [
            'operation_key' => 'quality-direct-purchase', 'receipt_key' => 'quality-direct-receipt', 'supplier_id' => $supplier['id'],
            'warehouse_id' => $warehouse->id, 'supplier_document_reference' => 'FACT-TEST-9', 'landed_cost_cents' => 1000,
            'lines' => [
                ['product_id' => $product['id'], 'presentation_id' => $product['presentations'][0]['id'], 'quantity' => 5, 'unit_cost_cents' => 2000],
                ['product_id' => $secondProduct['id'], 'presentation_id' => $secondProduct['presentations'][0]['id'], 'quantity' => 2, 'unit_cost_cents' => 5000],
            ],
        ])->assertCreated()->assertJsonCount(2, 'data.lines')->json('data');
        $this->assertSame('received', $purchase['status']);
        $this->assertSame(1000, (int) DB::table('purchase_receipt_lines')->whereIn('purchase_order_line_id', collect($purchase['lines'])->pluck('id'))->sum('landed_cost_cents'));
        $invoice = $this->postJson("/api/v1/purchase-orders/{$purchase['id']}/invoices", [
            'document_type' => 'invoice', 'series' => 'F001', 'number' => '44', 'issue_date' => now()->toDateString(),
            'total_cents' => 21000, 'taxable_base_cents' => 17797, 'igv_cents' => 3203,
            'exempt_amount_cents' => 0, 'unaffected_amount_cents' => 0, 'other_taxes_cents' => 0,
        ])->assertCreated()->assertJsonPath('data.order_amount_cents', 21000)
            ->assertJsonPath('data.variance_cents', 0)->assertJsonPath('data.reconciliation_status', 'matched')->json('data');
        $this->postJson("/api/v1/purchase-orders/{$purchase['id']}/invoices", [
            'document_type' => 'invoice', 'series' => 'F001', 'number' => '45', 'issue_date' => now()->toDateString(),
            'total_cents' => 22000, 'taxable_base_cents' => 18644, 'igv_cents' => 3356,
            'exempt_amount_cents' => 0, 'unaffected_amount_cents' => 0, 'other_taxes_cents' => 0,
        ])->assertUnprocessable()->assertJsonValidationErrors('reconciliation_note');
        $this->postJson("/api/v1/purchase-orders/{$purchase['id']}/invoices", [
            'document_type' => 'invoice', 'series' => 'F001', 'number' => '45', 'issue_date' => now()->toDateString(),
            'total_cents' => 22000, 'reconciliation_note' => 'Diferencia de redondeo documentada para control.',
            'taxable_base_cents' => 18644, 'igv_cents' => 3356, 'exempt_amount_cents' => 0,
            'unaffected_amount_cents' => 0, 'other_taxes_cents' => 0,
        ])->assertCreated()->assertJsonPath('data.variance_cents', 1000)
            ->assertJsonPath('data.reconciliation_status', 'difference_noted');
        $foreignInvoice = $this->postJson("/api/v1/purchase-orders/{$purchase['id']}/invoices", [
            'document_type' => 'invoice', 'series' => 'F001', 'number' => '46', 'issue_date' => now()->toDateString(),
            'currency_code' => 'USD', 'document_total_cents' => 6000, 'exchange_rate' => '3.5',
            'taxable_base_cents' => 5085, 'igv_cents' => 915, 'exempt_amount_cents' => 0,
            'unaffected_amount_cents' => 0, 'other_taxes_cents' => 0,
        ])->assertCreated()->assertJsonPath('data.currency_code', 'USD')
            ->assertJsonPath('data.document_total_cents', 6000)->assertJsonPath('data.total_cents', 21000)
            ->assertJsonPath('data.reconciliation_status', 'matched')->assertJsonPath('data.tax_breakdown_status', 'provided')->json('data');
        $this->assertSame('3.50000000', $foreignInvoice['exchange_rate']);
        $this->postJson("/api/v1/purchase-orders/{$purchase['id']}/invoices", [
            'document_type' => 'invoice', 'series' => 'F001', 'number' => '47', 'issue_date' => now()->toDateString(),
            'currency_code' => 'USD', 'document_total_cents' => 6000, 'exchange_rate' => '3.5',
            'taxable_base_cents' => 5084, 'igv_cents' => 915, 'exempt_amount_cents' => 0,
            'unaffected_amount_cents' => 0, 'other_taxes_cents' => 0, 'reconciliation_note' => 'Diferencia anotada.',
        ])->assertUnprocessable()->assertJsonValidationErrors('taxable_base_cents');
        $this->postJson("/api/v1/supplier-invoices/{$invoice['id']}/payments", [
            'operation_key' => 'quality-supplier-payment-001', 'method' => 'transfer', 'amount_cents' => 3000,
        ])->assertOk()->assertJsonPath('data.paid_cents', 3000)->assertJsonPath('data.status', 'partially_paid');
        $this->assertSame(18000, $invoice['total_cents'] - 3000);
    }

    public function test_stock_transfer_reduces_origin_then_receives_in_destination_partially(): void
    {
        $this->actingAs(User::factory()->create(), 'web');
        $source = Warehouse::query()->where('code', 'MAIN')->firstOrFail();
        $destination = $this->postJson('/api/v1/warehouses', [
            'code' => 'QUALITY-DEST', 'name' => 'Almacén destino de prueba', 'location_name' => 'General',
        ])->assertCreated()->json('data');
        $destinationLocationId = collect($destination['locations'])->firstWhere('is_default', true)['id'];
        $product = $this->postJson('/api/v1/products', [
            'sku' => 'QUALITY-TRANSFER-001', 'name' => 'Caja de vasos', 'base_unit' => 'unidad', 'minimum_stock' => 0,
            'presentations' => [['name' => 'unidad', 'factor_to_base' => 1, 'price_cents' => 200, 'is_base' => true]],
        ])->assertCreated()->json('data');
        $presentationId = $product['presentations'][0]['id'];
        $this->postJson('/api/v1/inventory/movements', [
            'product_id' => $product['id'], 'warehouse_id' => $source->id, 'presentation_id' => $presentationId,
            'type' => 'opening', 'quantity' => 10, 'reason' => 'Apertura de prueba', 'operation_key' => 'quality-transfer-opening',
        ])->assertCreated();
        $transfer = $this->postJson('/api/v1/inventory/transfers', [
            'operation_key' => 'quality-transfer-001', 'source_warehouse_id' => $source->id,
            'destination_warehouse_id' => $destination['id'], 'lines' => [[
                'product_id' => $product['id'], 'presentation_id' => $presentationId, 'quantity' => 4,
            ]],
        ])->assertCreated()->json('data');
        $sourceBalance = StockBalance::query()->where('product_id', $product['id'])->firstOrFail();
        $this->assertSame('6.000000', $sourceBalance->physical_quantity);
        $this->assertSame('0.000000', StockBalance::query()->where('product_id', $product['id'])->where('warehouse_location_id', $destinationLocationId)->value('physical_quantity') ?? '0.000000');
        $lineId = $transfer['lines'][0]['id'];
        $receiptPayload = ['operation_key' => 'quality-transfer-receipt-001', 'lines' => [['transfer_line_id' => $lineId, 'quantity' => 2]]];
        $this->postJson("/api/v1/inventory/transfers/{$transfer['id']}/receipts", $receiptPayload)
            ->assertOk()->assertJsonPath('data.status', 'partially_received');
        $this->postJson("/api/v1/inventory/transfers/{$transfer['id']}/receipts", $receiptPayload)
            ->assertOk()->assertJsonPath('data.status', 'partially_received');
        $this->postJson("/api/v1/inventory/transfers/{$transfer['id']}/receipts", [
            'operation_key' => 'quality-transfer-overreceipt', 'lines' => [['transfer_line_id' => $lineId, 'quantity' => 3]],
        ])->assertUnprocessable();
        $this->postJson("/api/v1/inventory/transfers/{$transfer['id']}/receipts", [
            'operation_key' => 'quality-transfer-receipt-002', 'lines' => [['transfer_line_id' => $lineId, 'quantity' => 2]],
        ])->assertOk()->assertJsonPath('data.status', 'received');
        $this->assertSame('4.000000', StockBalance::query()->where('product_id', $product['id'])->where('warehouse_location_id', $destinationLocationId)->value('physical_quantity'));
    }
}
