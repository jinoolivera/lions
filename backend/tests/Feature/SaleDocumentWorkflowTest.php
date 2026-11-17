<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockBalance;
use App\Models\SunatSetting;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SaleDocumentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_sale_can_be_billed_manually_and_traced_with_external_note_and_private_pdf(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'web');
        SunatSetting::query()->updateOrCreate(['id' => 1], ['billing_mode' => 'manual']);
        $warehouse = Warehouse::query()->where('code', 'MAIN')->firstOrFail();
        $product = Product::query()->create(['sku' => 'CPE-FLOW', 'name' => 'Artículo de prueba', 'base_unit' => 'unidad', 'minimum_stock' => 0, 'is_active' => true]);
        $presentation = $product->presentations()->create(['name' => 'unidad', 'factor_to_base' => 1, 'price_cents' => 2000, 'is_base' => true, 'is_active' => true]);
        StockBalance::query()->create(['warehouse_location_id' => $warehouse->locations()->firstOrFail()->id, 'product_id' => $product->id, 'physical_quantity' => 2, 'reserved_quantity' => 0]);
        $session = $this->postJson('/api/v1/cash/sessions', ['warehouse_id' => $warehouse->id, 'opening_cents' => 0])->assertCreated()->json('data');
        $customer = Customer::query()->create(['name' => 'Cliente RUC', 'tax_id_type' => 'ruc', 'tax_id' => '20123456789', 'is_active' => true, 'credit_limit_cents' => 0]);
        $base = ['cash_session_id' => $session['id'], 'lines' => [['product_id' => $product->id, 'presentation_id' => $presentation->id, 'quantity' => '1']], 'payments' => [['method' => 'cash', 'amount_cents' => 2000]]];
        $this->postJson('/api/v1/sales', $base + ['operation_key' => 'invalid-invoice', 'requested_document_type' => 'invoice'])->assertUnprocessable();
        $sale = $this->postJson('/api/v1/sales', $base + ['operation_key' => 'valid-sale', 'requested_document_type' => 'receipt'])->assertCreated()->json('data');
        $saleId = $sale['id'];
        $this->patchJson("/api/v1/sales/{$saleId}/billing", ['requested_document_type' => 'invoice', 'customer_id' => null])->assertUnprocessable();
        $this->patchJson("/api/v1/sales/{$saleId}/billing", ['requested_document_type' => 'invoice', 'customer_id' => $customer->id])->assertOk()->assertJsonPath('data.requested_document_type', 'invoice');
        Sale::query()->findOrFail($saleId)->documents()->create([
            'user_id' => auth()->id(), 'document_type' => 'invoice', 'series' => $warehouse->branch->invoice_series,
            'number' => '101', 'issue_date' => now()->toDateString(), 'total_cents' => 2000,
            'issued_via' => 'see_contributor', 'environment' => 'beta', 'status' => 'accepted',
        ]);
        $this->getJson('/api/v1/sales/archive?document=pending')->assertOk()->assertJsonPath('meta.total', 1);

        $document = $this->postJson("/api/v1/sales/{$saleId}/external-document", [
            'document_type' => 'invoice', 'series' => $warehouse->branch->invoice_series, 'number' => '101',
            'issue_date' => now()->toDateString(), 'total_cents' => 2000, 'issued_via' => 'see_sol',
            'pdf' => UploadedFile::fake()->create('factura.pdf', 50, 'application/pdf'),
        ])->assertCreated()->assertJsonPath('data.has_pdf', true)->json('data');
        $this->getJson("/api/v1/sales/{$saleId}/documents/{$document['id']}/pdf")->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->patchJson("/api/v1/sales/{$saleId}/billing", ['requested_document_type' => 'receipt', 'customer_id' => $customer->id])->assertStatus(409);
        $this->postJson("/api/v1/sales/{$saleId}/external-document", ['document_type' => 'invoice'])->assertUnprocessable();

        $note = $this->postJson("/api/v1/sales/{$saleId}/documents/{$document['id']}/adjustments", [
            'adjustment_type' => 'credit_note', 'series' => 'F001', 'number' => '501',
            'issue_date' => now()->toDateString(), 'total_cents' => 500, 'issued_via' => 'see_sol',
            'reason' => 'Devolución parcial', 'pdf' => UploadedFile::fake()->create('nota.pdf', 50, 'application/pdf'),
        ])->assertCreated()->assertJsonPath('data.has_pdf', true)->json('data');
        $this->getJson("/api/v1/sales/{$saleId}/documents/{$document['id']}/adjustments/{$note['id']}/pdf")->assertOk();
        $this->postJson("/api/v1/sales/{$saleId}/documents/{$document['id']}/adjustments", [
            'adjustment_type' => 'credit_note', 'series' => 'F001', 'number' => '502',
            'issue_date' => now()->toDateString(), 'total_cents' => 1600, 'issued_via' => 'see_sol', 'reason' => 'Crédito excesivo',
        ])->assertUnprocessable();
        $this->getJson("/api/v1/sales/{$saleId}")->assertOk()->assertJsonFragment(['number' => '501']);
        $this->getJson('/api/v1/sales/archive?document=external')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/sales/archive?document=pending')->assertOk()->assertJsonPath('meta.total', 0);
    }
}
