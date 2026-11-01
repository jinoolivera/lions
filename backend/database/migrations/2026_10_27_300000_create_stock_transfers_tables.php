<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('source_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('destination_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('transfer_number', 40)->unique();
            $table->string('operation_key', 100)->unique();
            $table->char('payload_hash', 64);
            $table->string('status', 24)->default('in_transit');
            $table->string('notes', 2000)->nullable();
            $table->timestamp('shipped_at');
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
            $table->index(['source_warehouse_id', 'destination_warehouse_id', 'status']);
        });

        Schema::create('stock_transfer_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_presentation_id')->constrained('product_presentations')->restrictOnDelete();
            $table->string('product_name_snapshot', 180);
            $table->string('presentation_name_snapshot', 80);
            $table->decimal('factor_to_base', 18, 6);
            $table->decimal('shipped_quantity', 18, 6);
            $table->decimal('received_quantity', 18, 6)->default(0);
            $table->decimal('unit_cost_base_cents', 18, 6)->nullable();
            $table->timestamps();
            $table->index(['stock_transfer_id', 'product_id']);
        });

        Schema::create('stock_transfer_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('operation_key', 100)->unique();
            $table->char('payload_hash', 64);
            $table->timestamp('received_at');
            $table->timestamps();
        });

        Schema::create('stock_transfer_receipt_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_transfer_receipt_id')->constrained()->restrictOnDelete();
            $table->foreignId('stock_transfer_line_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 18, 6);
            $table->timestamps();
            $table->index(['stock_transfer_receipt_id', 'stock_transfer_line_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_receipt_lines');
        Schema::dropIfExists('stock_transfer_receipts');
        Schema::dropIfExists('stock_transfer_lines');
        Schema::dropIfExists('stock_transfers');
    }
};
