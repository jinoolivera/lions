<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\SunatSetting;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Schema;

class SaleTicketPdf
{
    public function render(Sale $sale): string
    {
        $sale->loadMissing(['lines', 'payments', 'warehouse.branch']);
        $company = Schema::hasTable('sunat_settings') ? SunatSetting::query()->find(1) : null;
        $logo = base64_encode(file_get_contents(public_path('images/lions-logo.jpeg')));
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('pdf.sale-ticket', compact('sale', 'logo', 'company'))->render(), 'UTF-8');
        $pdf->setPaper([0, 0, 226.77, max(580, 440 + 60 * $sale->lines->count())]);
        $pdf->render();

        return $pdf->output();
    }
}
