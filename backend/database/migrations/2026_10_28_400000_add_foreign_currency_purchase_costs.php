<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->char('currency_code', 3)->default('PEN');
            $table->decimal('exchange_rate', 18, 8)->default(1);
            $table->date('exchange_rate_date')->nullable();
            $table->unsignedBigInteger('document_landed_cost_cents')->default(0);
        });
        Schema::table('purchase_order_lines', function (Blueprint $table): void {
            $table->unsignedBigInteger('document_unit_cost_cents')->default(0);
        });

        DB::table('purchase_orders')->update(['document_landed_cost_cents' => DB::raw('landed_cost_cents')]);
        DB::table('purchase_order_lines')->update(['document_unit_cost_cents' => DB::raw('unit_cost_cents')]);
    }

    public function down(): void
    {
        Schema::table('purchase_order_lines', function (Blueprint $table): void {
            $table->dropColumn('document_unit_cost_cents');
        });
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->dropColumn(['currency_code', 'exchange_rate', 'exchange_rate_date', 'document_landed_cost_cents']);
        });
    }
};
