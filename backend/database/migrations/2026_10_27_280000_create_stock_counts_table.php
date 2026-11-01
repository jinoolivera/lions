<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_counts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_location_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('inventory_movement_id')->nullable()->constrained()->nullOnDelete();
            $table->string('operation_key', 100)->unique();
            $table->char('payload_hash', 64);
            $table->decimal('system_quantity', 18, 6);
            $table->decimal('counted_quantity', 18, 6);
            $table->decimal('variance_quantity', 18, 6);
            $table->string('reason', 240);
            $table->timestamp('counted_at');
            $table->timestamps();
            $table->index(['warehouse_id', 'product_id', 'counted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_counts');
    }
};
