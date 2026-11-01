<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_balances', function (Blueprint $table): void {
            $table->decimal('average_unit_cost_cents', 18, 6)->nullable()->after('reserved_quantity');
        });
        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->decimal('unit_cost_cents', 18, 6)->nullable()->after('quantity_delta');
            $table->unsignedBigInteger('extended_cost_cents')->nullable()->after('unit_cost_cents');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->dropColumn(['unit_cost_cents', 'extended_cost_cents']);
        });
        Schema::table('stock_balances', function (Blueprint $table): void {
            $table->dropColumn('average_unit_cost_cents');
        });
    }
};
