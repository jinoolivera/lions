<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_brands', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('product_units', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 24)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $now = now();
        foreach (DB::table('products')->whereNotNull('brand')->distinct()->pluck('brand') as $brand) {
            $brand = trim((string) $brand);
            if ($brand !== '') DB::table('product_brands')->insertOrIgnore(['name' => $brand, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        }
        foreach (DB::table('products')->whereNotNull('base_unit')->distinct()->pluck('base_unit') as $unit) {
            $unit = trim((string) $unit);
            if ($unit !== '') DB::table('product_units')->insertOrIgnore(['name' => $unit, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        }
        foreach (['unidad', 'kg', 'g', 'litro', 'metro', 'par', 'docena'] as $unit) {
            DB::table('product_units')->insertOrIgnore(['name' => $unit, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_units');
        Schema::dropIfExists('product_brands');
    }
};
