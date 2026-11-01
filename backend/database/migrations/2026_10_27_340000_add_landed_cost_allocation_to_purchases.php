<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('landed_cost_cents')->default(0)->after('notes');
            $table->unsignedBigInteger('landed_cost_allocated_cents')->default(0)->after('landed_cost_cents');
        });
        Schema::table('purchase_receipt_lines', function (Blueprint $table): void {
            $table->unsignedBigInteger('landed_cost_cents')->default(0)->after('unit_cost_cents');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_receipt_lines', function (Blueprint $table): void {
            $table->dropColumn('landed_cost_cents');
        });
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->dropColumn(['landed_cost_cents', 'landed_cost_allocated_cents']);
        });
    }
};
