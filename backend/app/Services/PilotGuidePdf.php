<?php

namespace App\Services;

use App\Models\DeliveryShipment;
use App\Models\SunatSetting;
use Dompdf\Dompdf;
use Dompdf\Options;

class PilotGuidePdf
{
    public function render(DeliveryShipment $shipment): string
    {
        $shipment->loadMissing(['sale.warehouse.branch', 'lines.saleLine']);
        $company = SunatSetting::query()->find(1);
        $logo = base64_encode(file_get_contents(public_path('images/lions-logo.jpeg')));
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('pdf.pilot-guide', compact('shipment', 'company', 'logo'))->render(), 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();

        return $pdf->output();
    }
}
