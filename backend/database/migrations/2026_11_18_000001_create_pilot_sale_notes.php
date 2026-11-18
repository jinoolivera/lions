<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pilot_sale_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_name', 180);
            $table->decimal('quantity', 18, 6);
            $table->unsignedBigInteger('unit_price_cents');
            $table->string('customer_name', 180)->nullable();
            $table->string('payment_method', 20);
            $table->string('remarks', 500)->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestamp('sold_at');
            $table->timestamps();
            $table->index(['warehouse_id', 'status', 'sold_at']);
        });
    }

    public function down(): void { Schema::dropIfExists('pilot_sale_notes'); }
};
