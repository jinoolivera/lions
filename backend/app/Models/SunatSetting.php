<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SunatSetting extends Model
{
    protected $fillable = [
        'ruc', 'legal_name', 'trade_name', 'fiscal_address', 'ubigeo', 'environment',
        'billing_mode', 'sol_user', 'sol_password', 'certificate_path', 'certificate_name',
        'certificate_password', 'invoice_series', 'receipt_series', 'notification_email',
        'sending_enabled', 'next_invoice_beta', 'next_receipt_beta', 'next_invoice_production',
        'next_receipt_production', 'igv_rate',
    ];

    protected function casts(): array
    {
        return [
            'sol_password' => 'encrypted',
            'certificate_password' => 'encrypted',
            'sending_enabled' => 'boolean',
            'next_invoice_beta' => 'integer', 'next_receipt_beta' => 'integer',
            'next_invoice_production' => 'integer', 'next_receipt_production' => 'integer',
            'igv_rate' => 'decimal:2',
        ];
    }
}
