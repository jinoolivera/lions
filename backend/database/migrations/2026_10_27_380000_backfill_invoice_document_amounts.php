<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('supplier_invoices')->where('document_total_cents', 0)->update([
            'document_total_cents' => DB::raw('total_cents'),
            'currency_code' => 'PEN',
            'exchange_rate' => 1,
        ]);
    }

    public function down(): void
    {
        // This migration only restores legacy invoices with a missing document amount.
    }
};
