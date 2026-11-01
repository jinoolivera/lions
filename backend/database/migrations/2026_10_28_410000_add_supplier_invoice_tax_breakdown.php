<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_invoices', function (Blueprint $table): void {
            $table->unsignedBigInteger('taxable_base_cents')->nullable();
            $table->unsignedBigInteger('igv_cents')->nullable();
            $table->unsignedBigInteger('exempt_amount_cents')->nullable();
            $table->unsignedBigInteger('unaffected_amount_cents')->nullable();
            $table->unsignedBigInteger('other_taxes_cents')->nullable();
            $table->string('tax_breakdown_status', 20)->default('not_provided');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_invoices', function (Blueprint $table): void {
            $table->dropColumn(['taxable_base_cents', 'igv_cents', 'exempt_amount_cents', 'unaffected_amount_cents', 'other_taxes_cents', 'tax_breakdown_status']);
        });
    }
};
