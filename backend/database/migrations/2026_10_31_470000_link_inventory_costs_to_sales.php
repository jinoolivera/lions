<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->foreignId('sale_id')->nullable()->after('product_id')->constrained()->restrictOnDelete();
            $table->index(['sale_id', 'type', 'occurred_at']);
        });

        DB::table('sales')->select(['id', 'sale_number'])->orderBy('id')->chunkById(200, function ($sales): void {
            foreach ($sales as $sale) {
                DB::table('inventory_movements')->whereNull('sale_id')->where('type', 'sale')
                    ->where('reason', 'Venta '.$sale->sale_number)->update(['sale_id' => $sale->id]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->dropIndex(['sale_id', 'type', 'occurred_at']);
            $table->dropConstrainedForeignId('sale_id');
        });
    }
};
