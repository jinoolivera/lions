<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('opening_cents');
            $table->unsignedBigInteger('expected_cents')->default(0);
            $table->unsignedBigInteger('counted_cents')->nullable();
            $table->string('status', 16)->default('open');
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['warehouse_id', 'status']);
        });
        Schema::create('sales', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cash_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sale_number', 40)->unique();
            $table->string('operation_key', 100)->unique();
            $table->char('payload_hash', 64);
            $table->string('customer_name', 180)->nullable();
            $table->unsignedBigInteger('subtotal_cents');
            $table->unsignedBigInteger('total_cents');
            $table->string('status', 16)->default('completed');
            $table->timestamp('sold_at');
            $table->timestamps();
            $table->index(['warehouse_id', 'sold_at']);
        });
        Schema::create('sale_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_presentation_id')->constrained('product_presentations')->restrictOnDelete();
            $table->string('product_name_snapshot', 180);
            $table->string('presentation_name_snapshot', 80);
            $table->decimal('factor_to_base', 18, 6);
            $table->decimal('quantity', 18, 6);
            $table->unsignedBigInteger('unit_price_cents');
            $table->unsignedBigInteger('line_total_cents');
            $table->timestamps();
        });
        Schema::create('sale_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->string('method', 20);
            $table->unsignedBigInteger('amount_cents');
            $table->string('reference', 120)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_payments');
        Schema::dropIfExists('sale_lines');
        Schema::dropIfExists('sales');
        Schema::dropIfExists('cash_sessions');
    }
};
