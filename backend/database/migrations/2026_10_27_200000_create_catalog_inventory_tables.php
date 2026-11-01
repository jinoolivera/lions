<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')->nullable()->constrained('product_categories')->nullOnDelete();
            $table->string('sku', 64)->unique();
            $table->string('barcode', 64)->nullable()->unique();
            $table->string('name', 180)->index();
            $table->string('brand', 120)->nullable();
            $table->string('base_unit', 24);
            $table->decimal('minimum_stock', 18, 6)->default(0);
            $table->boolean('allows_fractional_quantity')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('product_presentations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('name', 80);
            $table->decimal('factor_to_base', 18, 6);
            $table->unsignedBigInteger('price_cents')->default(0);
            $table->boolean('is_base')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['product_id', 'name']);
            $table->index(['product_id', 'is_active']);
        });

        Schema::create('warehouses', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 120);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('warehouse_locations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name', 120);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->unique(['warehouse_id', 'code']);
            $table->index(['warehouse_id', 'is_default']);
        });

        Schema::create('stock_balances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warehouse_location_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('physical_quantity', 18, 6)->default(0);
            $table->decimal('reserved_quantity', 18, 6)->default(0);
            $table->timestamps();
            $table->unique(['warehouse_location_id', 'product_id']);
            $table->index(['product_id', 'warehouse_location_id']);
        });

        Schema::create('inventory_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warehouse_location_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_presentation_id')->nullable()->constrained('product_presentations')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 24);
            $table->decimal('quantity_delta', 18, 6);
            $table->string('reason', 240);
            $table->string('operation_key', 100)->unique();
            $table->char('payload_hash', 64);
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['product_id', 'warehouse_location_id', 'occurred_at'], 'inv_mov_prod_loc_occurred_idx');
        });

        DB::table('warehouses')->insert([
            'code' => 'MAIN', 'name' => 'Almacén principal', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $warehouseId = (int) DB::table('warehouses')->where('code', 'MAIN')->value('id');
        DB::table('warehouse_locations')->insert([
            'warehouse_id' => $warehouseId, 'code' => 'DEFAULT', 'name' => 'Ubicación general',
            'is_default' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('stock_balances');
        Schema::dropIfExists('warehouse_locations');
        Schema::dropIfExists('warehouses');
        Schema::dropIfExists('product_presentations');
        Schema::dropIfExists('products');
        Schema::dropIfExists('product_categories');
    }
};
