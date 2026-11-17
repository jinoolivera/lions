<?php

namespace Tests\Feature;

use App\Models\DeliveryGuide;
use App\Models\DeliveryShipment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockBalance;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DirectSaleShipmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_direct_sale_can_be_prepared_in_parts_with_one_external_guide_per_required_dispatch(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'web');
        $warehouse = Warehouse::query()->where('code', 'MAIN')->firstOrFail();
        $product = Product::query()->create([
            'sku' => 'SHIP-TEST-001', 'name' => 'Producto para envío', 'base_unit' => 'unidad',
            'minimum_stock' => 0, 'is_active' => true,
        ]);
        $presentation = $product->presentations()->create([
            'name' => 'unidad', 'factor_to_base' => 1, 'price_cents' => 1000, 'is_base' => true, 'is_active' => true,
        ]);
        $balance = StockBalance::query()->create([
            'warehouse_location_id' => $warehouse->locations()->firstOrFail()->id,
            'product_id' => $product->id, 'physical_quantity' => 5, 'reserved_quantity' => 0,
        ]);
        $session = $this->postJson('/api/v1/cash/sessions', [
            'warehouse_id' => $warehouse->id, 'opening_cents' => 0,
        ])->assertCreated()->json('data');
        $sale = $this->postJson('/api/v1/sales', [
            'operation_key' => 'sale-for-two-shipments', 'cash_session_id' => $session['id'],
            'lines' => [['product_id' => $product->id, 'presentation_id' => $presentation->id, 'quantity' => '3']],
            'payments' => [['method' => 'cash', 'amount_cents' => 3000]],
        ])->assertCreated()->json('data');
        $stockAfterSale = $balance->fresh()->physical_quantity;
        $base = [
            'recipient_name' => 'Cliente de prueba', 'delivery_address' => 'Av. Ejemplo 100, Lima',
            'delivery_contact' => '999999999', 'guide_required' => true,
        ];
        $firstPayload = $base + ['shipment_key' => 'ship-part-one', 'lines' => [['sale_line_id' => $sale['lines'][0]['id'], 'quantity' => '2']]];
        $first = $this->postJson("/api/v1/sales/{$sale['id']}/shipments", $firstPayload)
            ->assertCreated()->assertJsonPath('data.status', 'prepared')->json('data');
        $this->postJson("/api/v1/sales/{$sale['id']}/shipments", $firstPayload)->assertCreated()->assertJsonPath('data.id', $first['id']);
        $second = $this->postJson("/api/v1/sales/{$sale['id']}/shipments", array_replace($base, [
            'shipment_key' => 'ship-part-two', 'guide_required' => false,
            'lines' => [['sale_line_id' => $sale['lines'][0]['id'], 'quantity' => '1']],
        ]))->assertCreated()->json('data');
        $this->postJson("/api/v1/sales/{$sale['id']}/shipments", array_replace($base, [
            'shipment_key' => 'ship-too-many', 'lines' => [['sale_line_id' => $sale['lines'][0]['id'], 'quantity' => '1']],
        ]))->assertUnprocessable();
        $this->postJson("/api/v1/sales/{$sale['id']}/shipments/{$first['id']}/dispatch", ['guide_required' => true])
            ->assertUnprocessable();

        $guide = ['series' => 'EG07', 'number' => '123', 'issue_date' => now()->toDateString(), 'issued_via' => 'see_sol'];
        $this->postJson("/api/v1/sales/{$sale['id']}/shipments/{$first['id']}/dispatch", [
            'guide_required' => true, 'guide' => $guide,
        ])->assertOk()->assertJsonPath('data.status', 'dispatched')->assertJsonPath('data.guide.series', 'EG07');
        $this->getJson("/api/v1/sales/{$sale['id']}/shipments/{$first['id']}/guide/link")->assertStatus(422);
        $this->postJson("/api/v1/sales/{$sale['id']}/shipments/{$first['id']}/guide/pdf", [
            'pdf' => UploadedFile::fake()->create('gre.pdf', 50, 'application/pdf'),
        ])->assertOk()->assertJsonPath('data.has_pdf', true);
        $url = $this->getJson("/api/v1/sales/{$sale['id']}/shipments/{$first['id']}/guide/link")->assertOk()->json('url');
        $this->get(parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get('/guides/1/pdf')->assertForbidden();
        $this->postJson("/api/v1/sales/{$sale['id']}/shipments/{$first['id']}/guide/email", ['email' => 'cliente@example.com'])->assertStatus(503);
        config()->set('mail.default', 'resend');
        config()->set('services.resend.key', 'test-key');
        Mail::fake();
        $this->postJson("/api/v1/sales/{$sale['id']}/shipments/{$first['id']}/guide/email", ['email' => 'cliente@example.com'])->assertOk();
        Mail::assertSentCount(1);
        $this->postJson("/api/v1/sales/{$sale['id']}/shipments/{$first['id']}/dispatch", [
            'guide_required' => true, 'guide' => $guide,
        ])->assertConflict();
        $this->postJson("/api/v1/sales/{$sale['id']}/shipments/{$second['id']}/dispatch", [
            'guide_required' => true, 'guide' => $guide,
        ])->assertUnprocessable();
        $this->postJson("/api/v1/sales/{$sale['id']}/shipments/{$second['id']}/dispatch", [
            'guide_required' => false,
        ])->assertOk()->assertJsonPath('data.status', 'dispatched');

        $this->assertSame(2, DeliveryShipment::query()->where('sale_id', $sale['id'])->count());
        $this->assertSame(1, DeliveryGuide::query()->count());
        $this->assertSame(1, Sale::query()->count());
        $this->assertSame($stockAfterSale, $balance->fresh()->physical_quantity);
        $this->getJson('/api/v1/sales')->assertOk()->assertJsonPath('data.0.shipments.0.guide.number', '123');
    }

    public function test_cancelled_preparation_releases_its_quantity_for_a_new_shipment(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'web');
        $warehouse = Warehouse::query()->where('code', 'MAIN')->firstOrFail();
        $product = Product::query()->create([
            'sku' => 'SHIP-TEST-002', 'name' => 'Otro producto', 'base_unit' => 'unidad',
            'minimum_stock' => 0, 'is_active' => true,
        ]);
        $presentation = $product->presentations()->create([
            'name' => 'unidad', 'factor_to_base' => 1, 'price_cents' => 1000, 'is_base' => true, 'is_active' => true,
        ]);
        StockBalance::query()->create([
            'warehouse_location_id' => $warehouse->locations()->firstOrFail()->id,
            'product_id' => $product->id, 'physical_quantity' => 2, 'reserved_quantity' => 0,
        ]);
        $session = $this->postJson('/api/v1/cash/sessions', ['warehouse_id' => $warehouse->id, 'opening_cents' => 0])->assertCreated()->json('data');
        $sale = $this->postJson('/api/v1/sales', [
            'operation_key' => 'sale-cancel-prep', 'cash_session_id' => $session['id'],
            'lines' => [['product_id' => $product->id, 'presentation_id' => $presentation->id, 'quantity' => '1']],
            'payments' => [['method' => 'cash', 'amount_cents' => 1000]],
        ])->assertCreated()->json('data');
        $payload = ['recipient_name' => 'Cliente', 'delivery_address' => 'Calle 1', 'guide_required' => false,
            'lines' => [['sale_line_id' => $sale['lines'][0]['id'], 'quantity' => '1']]];
        $first = $this->postJson("/api/v1/sales/{$sale['id']}/shipments", $payload + ['shipment_key' => 'first-prep'])->assertCreated()->json('data');
        $this->postJson("/api/v1/sales/{$sale['id']}/shipments/{$first['id']}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postJson("/api/v1/sales/{$sale['id']}/shipments", $payload + ['shipment_key' => 'second-prep'])->assertCreated();
    }
}
