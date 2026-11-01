<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->date('due_date')->nullable()->index();
        });

        Schema::table('sale_payments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('sale_id');
            $table->foreignId('sale_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->index(['customer_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::table('sale_payments', function (Blueprint $table): void {
            $table->dropIndex(['customer_id', 'kind']);
            $table->dropConstrainedForeignId('customer_id');
            $table->dropConstrainedForeignId('sale_id');
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
        });
        Schema::table('sales', function (Blueprint $table): void {
            $table->dropIndex(['due_date']);
            $table->dropColumn('due_date');
        });
    }
};
