<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CashSession;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockBalance;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BranchAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_edit_two_branches_and_one_shared_company_identity(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin, 'web');

        $this->getJson('/api/v1/branches')->assertOk()->assertJsonCount(2, 'data');
        $branch = Branch::query()->where('code', 'LIONS_B')->firstOrFail();
        $this->assertNotSame(Branch::query()->where('code', 'LIONS_A')->value('invoice_series'), $branch->invoice_series);
        $this->assertNotSame(Branch::query()->where('code', 'LIONS_A')->value('receipt_series'), $branch->receipt_series);
        $this->patchJson('/api/v1/branches/'.$branch->id, [
            'name' => 'Lions Miraflores', 'address' => 'Av. de prueba 123',
            'invoice_series' => 'F003', 'receipt_series' => 'B003',
        ])->assertOk()->assertJsonPath('data.name', 'Lions Miraflores')->assertJsonPath('data.invoice_series', 'F003');
        $this->patchJson('/api/v1/branches/'.$branch->id, [
            'name' => 'Lions Miraflores', 'invoice_series' => Branch::query()->where('code', 'LIONS_A')->value('invoice_series'),
        ])->assertUnprocessable()->assertJsonValidationErrors('invoice_series');
        $this->patchJson('/api/v1/company', [
            'ruc' => '20123456789', 'legal_name' => 'Lions Import S.A.C.',
            'trade_name' => 'Lions Import', 'fiscal_address' => 'Lima, Perú', 'ubigeo' => '150101',
        ])->assertOk()->assertJsonPath('data.ruc', '20123456789');
        $this->getJson('/api/v1/company')->assertOk()->assertJsonPath('data.legal_name', 'Lions Import S.A.C.');
        $this->postJson('/api/v1/store-users', [
            'name' => 'Caja B', 'email' => 'caja-b@example.com', 'password' => 'test-password-123',
            'role' => 'cashier', 'branch_ids' => [$branch->id],
        ])->assertCreated()->assertJsonPath('data.branches.0.code', 'LIONS_B');
        $this->assertSame(2, Warehouse::query()->count());
    }

    public function test_store_user_only_sees_assigned_warehouse_and_cannot_open_another_cash(): void
    {
        $branchB = Branch::query()->where('code', 'LIONS_B')->firstOrFail();
        $warehouseA = Warehouse::query()->where('code', 'MAIN')->firstOrFail();
        $warehouseB = Warehouse::query()->where('code', 'LIONS_B')->firstOrFail();
        $cashier = User::factory()->create(['role' => 'cashier', 'email' => 'caja-b@example.com']);
        $cashier->branches()->attach($branchB->id);
        $this->actingAs($cashier, 'web');
        $this->getJson('/api/user')->assertOk()->assertJsonPath('email', 'caja-b@example.com');

        $this->getJson('/api/v1/branches')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'LIONS_B');
        $this->getJson('/api/v1/warehouses')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $warehouseB->id);
        $this->getJson('/api/v1/products?warehouse_id='.$warehouseA->id)->assertForbidden();
        $this->getJson('/api/v1/sales?warehouse_id='.$warehouseA->id)->assertForbidden();
        $this->postJson('/api/v1/cash/sessions', ['warehouse_id' => $warehouseA->id, 'opening_cents' => 0])->assertForbidden();
        $this->postJson('/api/v1/cash/sessions', ['warehouse_id' => $warehouseB->id, 'opening_cents' => 0])->assertCreated();
        $this->getJson('/api/v1/store-users')->assertForbidden();
        $this->patchJson('/api/v1/company', ['ruc' => '20123456789'])->assertForbidden();
    }

    public function test_cashier_sale_and_ticket_access_stay_in_the_assigned_store(): void
    {
        $branchB = Branch::query()->where('code', 'LIONS_B')->firstOrFail();
        $warehouseA = Warehouse::query()->where('code', 'MAIN')->firstOrFail();
        $warehouseB = Warehouse::query()->where('code', 'LIONS_B')->firstOrFail();
        $product = Product::query()->create([
            'sku' => 'BRANCH-TEST-001', 'name' => 'Producto de tienda', 'base_unit' => 'unidad',
            'minimum_stock' => 0, 'is_active' => true,
        ]);
        $presentation = $product->presentations()->create([
            'name' => 'unidad', 'factor_to_base' => 1, 'price_cents' => 1000, 'is_base' => true, 'is_active' => true,
        ]);
        StockBalance::query()->create([
            'warehouse_location_id' => $warehouseB->locations()->firstOrFail()->id,
            'product_id' => $product->id, 'physical_quantity' => 5, 'reserved_quantity' => 0,
        ]);
        $otherCash = CashSession::query()->create([
            'warehouse_id' => $warehouseA->id, 'opening_cents' => 0, 'expected_cents' => 0,
            'status' => 'open', 'opened_at' => now(),
        ]);
        $otherSale = Sale::query()->create([
            'cash_session_id' => $otherCash->id, 'warehouse_id' => $warehouseA->id,
            'sale_number' => 'V-OTHER-STORE', 'operation_key' => 'other-store-sale-001',
            'payload_hash' => str_repeat('a', 64), 'subtotal_cents' => 1000,
            'total_cents' => 1000, 'paid_cents' => 1000, 'status' => 'completed', 'sold_at' => now(),
        ]);
        $cashier = User::factory()->create(['role' => 'cashier']);
        $cashier->branches()->attach($branchB->id);
        $this->actingAs($cashier, 'web');
        $session = $this->postJson('/api/v1/cash/sessions', [
            'warehouse_id' => $warehouseB->id, 'opening_cents' => 0,
        ])->assertCreated()->json('data');
        $sale = $this->postJson('/api/v1/sales', [
            'operation_key' => 'branch-b-sale-001', 'cash_session_id' => $session['id'],
            'lines' => [['product_id' => $product->id, 'presentation_id' => $presentation->id, 'quantity' => 1]],
            'payments' => [['method' => 'cash', 'amount_cents' => 1000]],
        ])->assertCreated()->assertJsonPath('data.warehouse_id', $warehouseB->id)->json('data');
        $this->getJson('/api/v1/sales')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $sale['id']);
        $this->getJson('/api/v1/sales/archive')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $sale['id']);
        $this->getJson('/api/v1/sales/archive?warehouse_id='.$warehouseA->id)->assertForbidden();
        $this->getJson('/api/v1/sales/'.$sale['id'])->assertOk()->assertJsonPath('data.id', $sale['id']);
        $this->getJson('/api/v1/sales/'.$otherSale->id)->assertForbidden();
        $this->patchJson('/api/v1/sales/'.$otherSale->id.'/billing', ['requested_document_type' => 'receipt', 'customer_id' => null])->assertForbidden();
        $this->getJson('/api/v1/sales?warehouse_id='.$warehouseA->id)->assertForbidden();
        $this->getJson('/api/v1/sales/'.$sale['id'].'/ticket/link')->assertOk();
        $this->getJson('/api/v1/sales/'.$otherSale->id.'/ticket/link')->assertForbidden();
        $shipmentData = [
            'shipment_key' => 'branch-b-shipment-001', 'recipient_name' => 'Cliente tienda B',
            'delivery_address' => 'Calle B 123', 'guide_required' => false,
            'lines' => [['sale_line_id' => $sale['lines'][0]['id'], 'quantity' => '1']],
        ];
        $this->postJson('/api/v1/sales/'.$otherSale->id.'/shipments', $shipmentData)->assertForbidden();
        $shipment = $this->postJson('/api/v1/sales/'.$sale['id'].'/shipments', $shipmentData)->assertCreated()->json('data');
        $this->postJson('/api/v1/sales/'.$otherSale->id.'/shipments/'.$shipment['id'].'/dispatch', ['guide_required' => false])->assertForbidden();
        $this->postJson('/api/v1/sales/'.$sale['id'].'/shipments/'.$shipment['id'].'/dispatch', ['guide_required' => false])->assertOk();
    }
}
