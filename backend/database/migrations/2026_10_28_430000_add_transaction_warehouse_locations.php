<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', fn (Blueprint $table) => $table->foreignId('warehouse_location_id')->nullable()->after('warehouse_id')->constrained()->restrictOnDelete());
        Schema::table('delivery_orders', fn (Blueprint $table) => $table->foreignId('warehouse_location_id')->nullable()->after('warehouse_id')->constrained()->restrictOnDelete());
        Schema::table('purchase_receipts', fn (Blueprint $table) => $table->foreignId('warehouse_location_id')->nullable()->after('warehouse_id')->constrained()->restrictOnDelete());
        Schema::table('stock_transfers', function (Blueprint $table): void {
            $table->foreignId('source_location_id')->nullable()->constrained('warehouse_locations')->restrictOnDelete();
            $table->foreignId('destination_location_id')->nullable()->constrained('warehouse_locations')->restrictOnDelete();
        });
        Schema::table('sale_lines', fn (Blueprint $table) => $table->decimal('returned_quantity', 18, 6)->default(0)->after('discount_approved_by_user_id'));
        Schema::table('sales', fn (Blueprint $table) => $table->unsignedBigInteger('returned_cents')->default(0)->after('paid_cents'));
        Schema::create('sale_returns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->foreignId('cash_session_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('return_number', 40)->unique();
            $table->string('operation_key', 100)->unique();
            $table->char('payload_hash', 64);
            $table->string('reason', 500);
            $table->string('refund_method', 20)->nullable();
            $table->unsignedBigInteger('amount_cents');
            $table->unsignedBigInteger('refund_cents')->default(0);
            $table->timestamp('returned_at');
            $table->timestamps();
        });
        Schema::create('sale_return_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sale_return_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_line_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 18, 6);
            $table->unsignedBigInteger('amount_cents');
            $table->timestamps();
            $table->unique(['sale_return_id', 'sale_line_id']);
        });

        DB::table('sales')->whereNull('warehouse_location_id')->orderBy('id')->each(function ($sale): void {
            $location = DB::table('warehouse_locations')->where('warehouse_id', $sale->warehouse_id)->orderByDesc('is_default')->orderBy('id')->value('id');
            if ($location) DB::table('sales')->where('id', $sale->id)->update(['warehouse_location_id' => $location]);
        });
        DB::table('delivery_orders')->whereNull('warehouse_location_id')->orderBy('id')->each(function ($order): void {
            $location = DB::table('warehouse_locations')->where('warehouse_id', $order->warehouse_id)->orderByDesc('is_default')->orderBy('id')->value('id');
            if ($location) DB::table('delivery_orders')->where('id', $order->id)->update(['warehouse_location_id' => $location]);
        });
        DB::table('purchase_receipts')->whereNull('warehouse_location_id')->orderBy('id')->each(function ($receipt): void {
            $location = DB::table('warehouse_locations')->where('warehouse_id', $receipt->warehouse_id)->orderByDesc('is_default')->orderBy('id')->value('id');
            if ($location) DB::table('purchase_receipts')->where('id', $receipt->id)->update(['warehouse_location_id' => $location]);
        });
        DB::table('stock_transfers')->whereNull('source_location_id')->orderBy('id')->each(function ($transfer): void {
            $source = DB::table('warehouse_locations')->where('warehouse_id', $transfer->source_warehouse_id)->orderByDesc('is_default')->orderBy('id')->value('id');
            $destination = DB::table('warehouse_locations')->where('warehouse_id', $transfer->destination_warehouse_id)->orderByDesc('is_default')->orderBy('id')->value('id');
            DB::table('stock_transfers')->where('id', $transfer->id)->update(['source_location_id' => $source, 'destination_location_id' => $destination]);
        });
    }

    public function down(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('source_location_id');
            $table->dropConstrainedForeignId('destination_location_id');
        });
        Schema::dropIfExists('sale_return_lines');
        Schema::dropIfExists('sale_returns');
        Schema::table('sale_lines', fn (Blueprint $table) => $table->dropColumn('returned_quantity'));
        Schema::table('sales', fn (Blueprint $table) => $table->dropColumn('returned_cents'));
        Schema::table('purchase_receipts', fn (Blueprint $table) => $table->dropConstrainedForeignId('warehouse_location_id'));
        Schema::table('delivery_orders', fn (Blueprint $table) => $table->dropConstrainedForeignId('warehouse_location_id'));
        Schema::table('sales', fn (Blueprint $table) => $table->dropConstrainedForeignId('warehouse_location_id'));
    }
};
