<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_invoices', function (Blueprint $table): void {
            $table->unsignedBigInteger('document_total_cents')->default(0)->after('currency_code');
            $table->decimal('exchange_rate', 18, 8)->default(1)->after('document_total_cents');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_invoices', function (Blueprint $table): void {
            $table->dropColumn(['document_total_cents', 'exchange_rate']);
        });
    }
};
