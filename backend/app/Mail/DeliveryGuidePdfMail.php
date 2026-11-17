<?php

namespace App\Mail;

use App\Models\DeliveryGuide;
use App\Models\Sale;
use Illuminate\Mail\Mailable;

class DeliveryGuidePdfMail extends Mailable
{
    public function __construct(
        public readonly Sale $sale,
        public readonly DeliveryGuide $guide,
        private readonly string $pdf,
    ) {}

    public function build(): static
    {
        return $this->subject('Guía de Remisión '.$this->guide->series.'-'.$this->guide->number.' · U.LIONS')
            ->view('emails.delivery-guide')
            ->attachData($this->pdf, 'GRE-'.$this->guide->series.'-'.$this->guide->number.'.pdf', ['mime' => 'application/pdf']);
    }
}
