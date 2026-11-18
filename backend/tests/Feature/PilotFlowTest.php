<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\PilotSaleNote;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PilotFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_quick_freeform_note_is_timestamped_and_scoped_to_its_store(): void
    {
        config()->set('lions.pilot_mode', true);
        $branch = Branch::query()->where('code', 'LIONS_A')->firstOrFail();
        $warehouse = Warehouse::query()->where('branch_id', $branch->id)->firstOrFail();
        $manager = User::factory()->create(['role' => 'manager']);
        $manager->branches()->attach($branch->id);
        $this->actingAs($manager, 'web');
        $note = $this->postJson('/api/v1/pilot-notes', [
            'warehouse_id' => $warehouse->id,
            'body' => '2 pulseras doradas, S/ 20 c/u, efectivo',
        ])->assertCreated()->assertJsonPath('data.status', 'pending')->json('data');
        $this->assertNotEmpty($note['sold_at']);
        $this->getJson('/api/v1/pilot-notes?warehouse_id='.$warehouse->id)->assertOk()
            ->assertJsonPath('data.0.body', '2 pulseras doradas, S/ 20 c/u, efectivo');
    }

    public function test_store_manager_shares_master_catalog_and_regularizes_note_once_without_stock(): void
    {
        config()->set('lions.pilot_mode', true);
        $branchA = Branch::query()->where('code', 'LIONS_A')->firstOrFail();
        $branchB = Branch::query()->where('code', 'LIONS_B')->firstOrFail();
        $warehouseA = Warehouse::query()->where('branch_id', $branchA->id)->firstOrFail();
        $warehouseB = Warehouse::query()->where('branch_id', $branchB->id)->firstOrFail();
        $managerA = User::factory()->create(['role' => 'manager']);
        $managerA->branches()->attach($branchA->id);
        $managerB = User::factory()->create(['role' => 'manager']);
        $managerB->branches()->attach($branchB->id);
        $this->assertContains($warehouseB->id, \App\Support\BranchAccess::warehouseIds($managerB));

        $this->actingAs($managerA, 'web');
        $product = $this->postJson('/api/v1/products', [
            'sku' => 'PILOT-ONE', 'name' => 'Producto real piloto', 'base_unit' => 'unidad', 'minimum_stock' => 0,
            'presentations' => [['name' => 'unidad', 'factor_to_base' => 1, 'price_cents' => 1000, 'is_base' => true]],
        ])->assertCreated()->json('data');
        $this->postJson('/api/v1/pilot-notes', [
            'warehouse_id' => $warehouseB->id, 'product_name' => 'Producto sin registrar',
            'quantity' => 2, 'unit_price_cents' => 1200, 'payment_method' => 'cash',
        ])->assertForbidden();

        auth()->forgetGuards();
        $this->actingAs($managerB, 'web');
        $this->getJson('/api/v1/products?warehouse_id='.$warehouseB->id)->assertOk()
            ->assertJsonPath('pilot_mode', true)->assertJsonPath('data.0.sku', 'PILOT-ONE');
        $note = $this->postJson('/api/v1/pilot-notes', [
            'warehouse_id' => $warehouseB->id, 'product_name' => 'Producto sin registrar',
            'quantity' => 2, 'unit_price_cents' => 1200, 'payment_method' => 'cash',
        ])->assertCreated()->json('data');
        $cash = $this->postJson('/api/v1/cash/sessions', ['warehouse_id' => $warehouseB->id, 'opening_cents' => 0])
            ->assertCreated()->json('data');
        $payload = [
            'operation_key' => 'pilot-note-'. $note['id'], 'cash_session_id' => $cash['id'], 'pilot_note_id' => $note['id'],
            'lines' => [['product_id' => $product['id'], 'presentation_id' => $product['presentations'][0]['id'],
                'quantity' => 2, 'unit_price_cents' => 1200]],
            'payments' => [['method' => 'cash', 'amount_cents' => 2400]],
        ];
        $sale = $this->postJson('/api/v1/sales', $payload)->assertCreated()->json('data');
        $this->assertSame($sale['id'], PilotSaleNote::query()->findOrFail($note['id'])->sale_id);
        $balance = StockBalance::query()->where('product_id', $product['id'])
            ->where('warehouse_location_id', $warehouseB->locations()->firstOrFail()->id)->firstOrFail();
        $this->assertSame('-2.000000', $balance->physical_quantity);
        $this->postJson('/api/v1/sales', array_merge($payload, ['operation_key' => 'another-key']))->assertUnprocessable();
        $this->assertSame(1, PilotSaleNote::query()->where('status', 'regularized')->count());
        $this->assertSame(1, \App\Models\Sale::query()->count());

        $shipment = $this->postJson('/api/v1/sales/'.$sale['id'].'/shipments', [
            'shipment_key' => 'pilot-shipment-1', 'recipient_name' => 'Cliente piloto',
            'delivery_address' => 'Av. Piloto 100', 'guide_required' => true,
            'lines' => [['sale_line_id' => $sale['lines'][0]['id'], 'quantity' => 2]],
        ])->assertCreated()->json('data');
        $link = $this->getJson('/api/v1/sales/'.$sale['id'].'/shipments/'.$shipment['id'].'/pilot-guide/link')
            ->assertOk()->json('url');
        $path = parse_url($link, PHP_URL_PATH).'?'.parse_url($link, PHP_URL_QUERY);
        $this->get($path)->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->postJson('/api/v1/sales/'.$sale['id'].'/shipments/'.$shipment['id'].'/dispatch', ['guide_required' => true])
            ->assertUnprocessable();
    }
}
