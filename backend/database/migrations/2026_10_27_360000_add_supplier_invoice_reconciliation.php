<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_invoices', function (Blueprint $table): void {
            $table->unsignedBigInteger('order_amount_cents')->default(0)->after('total_cents');
            $table->bigInteger('variance_cents')->default(0)->after('order_amount_cents');
            $table->string('reconciliation_status', 24)->default('legacy')->after('variance_cents');
            $table->string('reconciliation_note', 500)->nullable()->after('reconciliation_status');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_invoices', function (Blueprint $table): void {
            $table->dropColumn(['order_amount_cents', 'variance_cents', 'reconciliation_status', 'reconciliation_note']);
        });
    }
};
