<?php

namespace Tests\Unit;

use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\Warehouse;
use App\Services\SaleTicketPdf;
use Illuminate\Database\Eloquent\Collection;
use Tests\TestCase;

class SaleTicketPdfTest extends TestCase
{
    public function test_it_renders_a_sale_ticket_pdf_with_the_store_and_total(): void
    {
        $sale = new Sale([
            'sale_number' => 'V-TEST-001', 'customer_name' => 'Cliente prueba',
            'total_cents' => 1990, 'paid_cents' => 1990, 'sold_at' => now(),
        ]);
        $sale->setRelation('warehouse', new Warehouse(['name' => 'Tienda Centro']));
        $sale->setRelation('lines', new Collection([
            new SaleLine([
                'product_name_snapshot' => 'Producto prueba', 'presentation_name_snapshot' => 'Unidad',
                'quantity' => 1, 'unit_price_cents' => 1990, 'line_total_cents' => 1990,
                'discount_cents' => 0,
            ]),
        ]));
        $sale->setRelation('payments', new Collection);

        $pdf = app(SaleTicketPdf::class)->render($sale);

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertGreaterThan(2000, strlen($pdf));
    }
}
