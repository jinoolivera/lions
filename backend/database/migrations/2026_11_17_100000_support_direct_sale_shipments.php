<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_shipments', function (Blueprint $table): void {
            $table->foreignId('delivery_order_id')->nullable()->change();
            $table->timestamp('delivered_at')->nullable()->change();
            $table->string('status', 24)->default('dispatched');
            $table->string('recipient_name', 180)->nullable();
            $table->string('delivery_address', 240)->nullable();
            $table->string('delivery_contact', 120)->nullable();
            $table->boolean('guide_required')->default(false);
            $table->index(['sale_id', 'status']);
        });

        Schema::table('delivery_shipment_lines', function (Blueprint $table): void {
            $table->foreignId('delivery_order_line_id')->nullable()->change();
            $table->foreignId('sale_line_id')->nullable()->constrained()->restrictOnDelete();
            $table->unique(['delivery_shipment_id', 'sale_line_id']);
        });
    }

    public function down(): void
    {
        Schema::table('delivery_shipment_lines', function (Blueprint $table): void {
            $table->dropUnique(['delivery_shipment_id', 'sale_line_id']);
            $table->dropConstrainedForeignId('sale_line_id');
            $table->foreignId('delivery_order_line_id')->nullable(false)->change();
        });

        Schema::table('delivery_shipments', function (Blueprint $table): void {
            $table->dropIndex(['sale_id', 'status']);
            $table->dropColumn(['status', 'recipient_name', 'delivery_address', 'delivery_contact', 'guide_required']);
            $table->foreignId('delivery_order_id')->nullable(false)->change();
            $table->timestamp('delivered_at')->nullable(false)->change();
        });
    }
};
