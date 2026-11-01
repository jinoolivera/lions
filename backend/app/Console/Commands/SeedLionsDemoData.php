<?php

namespace App\Console\Commands;

use App\Models\CashSession;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\DeliveryOrderLine;
use App\Models\DeliveryPick;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductPresentation;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\DeliveryOrderService;
use App\Services\InventoryService;
use App\Services\PurchaseReceptionService;
use App\Services\SalesService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SeedLionsDemoData extends Command
{
    protected $signature = 'lions:demo:seed';

    protected $description = 'Create idempotent, clearly labeled local demo transactions for Lions ERP';

    public function handle(
        InventoryService $inventory,
        PurchaseReceptionService $receipts,
        SalesService $sales,
        DeliveryOrderService $deliveries,
    ): int {
        if (app()->environment('production')) {
            $this->components->error('Demo data is blocked in production.');

            return self::FAILURE;
        }

        $actor = User::query()->where('email', 'admin@lions.com')->first();
        if (! $actor) {
            $this->components->error('Create or select the local administrator account first.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($actor, $inventory, $receipts, $sales, $deliveries): void {
            $warehouse = Warehouse::query()->where('code', 'MAIN')->firstOrFail();
            $category = ProductCategory::query()->firstOrCreate(['name' => 'DEMO · Organización del hogar']);
            $product = Product::query()->firstOrCreate(['sku' => 'DEMO-LN-001'], [
                'category_id' => $category->id, 'barcode' => null,
                'name' => 'DEMO · Caja organizadora multiuso', 'brand' => 'DEMO Lions',
                'base_unit' => 'unidad', 'minimum_stock' => '24',
                'allows_fractional_quantity' => false, 'is_active' => true,
            ]);
            $unit = ProductPresentation::query()->firstOrCreate(
                ['product_id' => $product->id, 'name' => 'unidad'],
                ['factor_to_base' => '1.000000', 'price_cents' => 1990, 'is_base' => true, 'is_active' => true],
            );
            $case = ProductPresentation::query()->firstOrCreate(
                ['product_id' => $product->id, 'name' => 'caja x 12'],
                ['factor_to_base' => '12.000000', 'price_cents' => 19900, 'is_base' => false, 'is_active' => true],
            );
            $supplier = Supplier::query()->firstOrCreate(['name' => 'DEMO · Proveedor de artículos para el hogar'], [
                'email' => 'proveedor-demo@example.test', 'phone' => null,
                'address' => 'Dirección ficticia para demostración', 'is_active' => true,
            ]);
            $customer = Customer::query()->firstOrCreate(['name' => 'DEMO · Bodega San Martín'], [
                'tax_id' => null, 'email' => 'cliente-demo@example.test', 'phone' => null,
                'address' => 'Dirección ficticia para demostración', 'credit_limit_cents' => 1000000, 'is_active' => true,
            ]);
            $supplier->forceFill(['phone' => null])->save();
            $customer->forceFill(['phone' => null])->save();

            $openingKey = 'demo-v1-opening-stock';
            if (! DB::table('inventory_movements')->where('operation_key', $openingKey)->exists()) {
                $location = $warehouse->locations()->where('is_default', true)->firstOrFail();
                $inventory->record($product, $location, $case, 'opening', '20',
                    'DEMO: saldo inicial de demostración', $openingKey, $actor->id, '12000');
            }

            $purchase = PurchaseOrder::query()->firstOrCreate(['operation_key' => 'demo-v1-purchase'], [
                'supplier_id' => $supplier->id, 'user_id' => $actor->id, 'order_number' => 'DEMO-PO-001',
                'payload_hash' => hash('sha256', 'demo-v1-purchase'), 'status' => 'ordered',
                'notes' => 'DEMO: ejemplo de compra con recepción parcial', 'ordered_at' => now(),
            ]);
            $purchaseLine = PurchaseOrderLine::query()->firstOrCreate([
                'purchase_order_id' => $purchase->id, 'product_id' => $product->id,
                'product_presentation_id' => $case->id,
            ], [
                'product_name_snapshot' => $product->name, 'presentation_name_snapshot' => $case->name,
                'factor_to_base' => $case->factor_to_base, 'ordered_quantity' => '10.000000',
                'received_quantity' => '0.000000', 'unit_cost_cents' => 13200,
            ]);
            if (! DB::table('purchase_receipts')->where('receipt_key', 'demo-v1-receipt')->exists()) {
                $receipts->receive($purchase, [
                    'receipt_key' => 'demo-v1-receipt', 'warehouse_id' => $warehouse->id,
                    'supplier_document_reference' => 'DEMO-GUIA-001', 'notes' => 'DEMO: primera mitad recibida',
                    'lines' => [['purchase_order_line_id' => $purchaseLine->id, 'quantity' => '5']],
                ], $actor->id);
            }
            SupplierInvoice::query()->firstOrCreate([
                'supplier_id' => $supplier->id, 'document_type' => 'invoice', 'series' => 'DEMO01', 'number' => '000001',
            ], [
                'purchase_order_id' => $purchase->id, 'user_id' => $actor->id,
                'issue_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(),
                'currency_code' => 'PEN', 'total_cents' => 132000, 'paid_cents' => 0, 'status' => 'open',
            ]);

            $session = CashSession::query()->where('warehouse_id', $warehouse->id)->where('status', 'open')->first();
            if (! $session) {
                $session = CashSession::query()->create([
                    'warehouse_id' => $warehouse->id, 'user_id' => $actor->id,
                    'opening_cents' => 5000, 'expected_cents' => 5000, 'status' => 'open', 'opened_at' => now(),
                ]);
            }

            $sale = Sale::query()->where('operation_key', 'demo-v1-sale')->first();
            if (! $sale) {
                $sale = $sales->create([
                    'operation_key' => 'demo-v1-sale', 'cash_session_id' => $session->id,
                    'customer_id' => $customer->id,
                    'lines' => [['product_id' => $product->id, 'presentation_id' => $case->id, 'quantity' => '1']],
                    'payments' => [
                        ['method' => 'cash', 'amount_cents' => 5000],
                        ['method' => 'transfer', 'amount_cents' => 4900, 'reference' => 'DEMO-TRANSFER-01'],
                    ],
                ], $actor->id);
            }
            if (! SalePayment::query()->where('operation_key', 'demo-v1-collection')->exists() && $sale->total_cents - $sale->paid_cents >= 3000) {
                SalePayment::query()->create([
                    'sale_id' => $sale->id, 'cash_session_id' => $session->id, 'user_id' => $actor->id,
                    'kind' => 'collection', 'method' => 'cash', 'amount_cents' => 3000,
                    'reference' => 'DEMO: cobranza parcial', 'operation_key' => 'demo-v1-collection',
                    'payload_hash' => hash('sha256', 'demo-v1-collection'),
                ]);
                $sale->increment('paid_cents', 3000);
                $session->increment('expected_cents', 3000);
            }

            $order = DeliveryOrder::query()->where('operation_key', 'demo-v1-delivery-order')->first();
            if (! $order) {
                $order = $deliveries->create([
                    'operation_key' => 'demo-v1-delivery-order', 'warehouse_id' => $warehouse->id,
                    'customer_id' => $customer->id, 'notes' => 'DEMO: pedido presencial con despacho parcial',
                    'lines' => [['product_id' => $product->id, 'presentation_id' => $case->id, 'quantity' => '2']],
                ], $actor->id);
            }
            $orderLine = DeliveryOrderLine::query()->where('delivery_order_id', $order->id)->firstOrFail();
            if (! DeliveryPick::query()->where('pick_key', 'demo-v1-pick')->exists()) {
                $deliveries->pick($order, [
                    'operation_key' => 'demo-v1-pick', 'notes' => 'DEMO: preparación parcial',
                    'lines' => [['delivery_order_line_id' => $orderLine->id, 'quantity' => '1']],
                ], $actor->id);
            }
            if (! DB::table('delivery_shipments')->where('shipment_key', 'demo-v1-shipment')->exists()) {
                $deliveries->deliver($order, [
                    'shipment_key' => 'demo-v1-shipment', 'notes' => 'DEMO: primera entrega',
                    'lines' => [['delivery_order_line_id' => $orderLine->id, 'quantity' => '1']],
                ], $actor->id);
            }
        });

        $this->components->info('Datos de demostración registrados. No son datos reales de clientes o proveedores.');

        return self::SUCCESS;
    }
}
