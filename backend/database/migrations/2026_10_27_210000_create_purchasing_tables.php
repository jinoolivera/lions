<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table): void {
            $table->id();
            $table->string('tax_id', 20)->nullable()->unique();
            $table->string('name', 180)->index();
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->text('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('purchase_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('order_number', 40)->unique();
            $table->string('operation_key', 100)->unique();
            $table->char('payload_hash', 64);
            $table->string('status', 24)->default('ordered');
            $table->text('notes')->nullable();
            $table->timestamp('ordered_at');
            $table->timestamps();
            $table->index(['supplier_id', 'status', 'ordered_at']);
        });

        Schema::create('purchase_order_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_presentation_id')->constrained()->restrictOnDelete();
            $table->string('product_name_snapshot', 180);
            $table->string('presentation_name_snapshot', 80);
            $table->decimal('factor_to_base', 18, 6);
            $table->decimal('ordered_quantity', 18, 6);
            $table->decimal('received_quantity', 18, 6)->default(0);
            $table->unsignedBigInteger('unit_cost_cents');
            $table->timestamps();
            $table->index(['purchase_order_id', 'product_id']);
        });

        Schema::create('purchase_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('receipt_key', 100)->unique();
            $table->char('payload_hash', 64);
            $table->string('supplier_document_reference', 120)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('received_at');
            $table->timestamps();
            $table->index(['purchase_order_id', 'received_at']);
        });

        Schema::create('purchase_receipt_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_receipt_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_order_line_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 18, 6);
            $table->unsignedBigInteger('unit_cost_cents');
            $table->timestamps();
            $table->index(['purchase_receipt_id', 'purchase_order_line_id'], 'purchase_receipt_lines_order_line_idx');
        });

        Schema::create('supplier_invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('document_type', 24);
            $table->string('series', 20);
            $table->string('number', 32);
            $table->date('issue_date');
            $table->date('due_date')->nullable();
            $table->char('currency_code', 3)->default('PEN');
            $table->unsignedBigInteger('total_cents');
            $table->unsignedBigInteger('paid_cents')->default(0);
            $table->string('status', 20)->default('open');
            $table->timestamps();
            $table->unique(['supplier_id', 'document_type', 'series', 'number'], 'supplier_invoice_reference_unique');
            $table->index(['supplier_id', 'status', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_invoices');
        Schema::dropIfExists('purchase_receipt_lines');
        Schema::dropIfExists('purchase_receipts');
        Schema::dropIfExists('purchase_order_lines');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('suppliers');
    }
};
