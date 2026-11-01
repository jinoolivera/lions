<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('order_number', 40)->unique();
            $table->string('operation_key', 100)->unique();
            $table->char('payload_hash', 64);
            $table->string('status', 24)->default('confirmed');
            $table->text('notes')->nullable();
            $table->timestamp('ordered_at');
            $table->timestamps();
            $table->index(['warehouse_id', 'status', 'ordered_at']);
            $table->index(['customer_id', 'status']);
        });
        Schema::create('delivery_order_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('delivery_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_presentation_id')->constrained('product_presentations')->restrictOnDelete();
            $table->string('product_name_snapshot', 180);
            $table->string('presentation_name_snapshot', 80);
            $table->decimal('factor_to_base', 18, 6);
            $table->decimal('ordered_quantity', 18, 6);
            $table->decimal('picked_quantity', 18, 6)->default(0);
            $table->decimal('delivered_quantity', 18, 6)->default(0);
            $table->unsignedBigInteger('unit_price_cents');
            $table->timestamps();
            $table->index(['delivery_order_id', 'product_id']);
        });
        Schema::create('delivery_shipments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('delivery_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();
            $table->string('shipment_key', 100)->unique();
            $table->char('payload_hash', 64);
            $table->text('notes')->nullable();
            $table->timestamp('delivered_at');
            $table->timestamps();
            $table->index(['delivery_order_id', 'delivered_at']);
        });
        Schema::create('delivery_shipment_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('delivery_shipment_id')->constrained()->restrictOnDelete();
            $table->foreignId('delivery_order_line_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 18, 6);
            $table->timestamps();
        });
        Schema::create('delivery_picks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('delivery_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('pick_key', 100)->unique();
            $table->char('payload_hash', 64);
            $table->text('notes')->nullable();
            $table->timestamp('picked_at');
            $table->timestamps();
        });
        Schema::create('delivery_pick_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('delivery_pick_id')->constrained()->restrictOnDelete();
            $table->foreignId('delivery_order_line_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 18, 6);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_shipment_lines');
        Schema::dropIfExists('delivery_pick_lines');
        Schema::dropIfExists('delivery_picks');
        Schema::dropIfExists('delivery_shipments');
        Schema::dropIfExists('delivery_order_lines');
        Schema::dropIfExists('delivery_orders');
    }
};
