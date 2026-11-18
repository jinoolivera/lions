<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('pilot_sale_notes', function (Blueprint $table): void {
            $table->text('body')->nullable();
            $table->string('product_name', 180)->nullable()->change();
            $table->decimal('quantity', 18, 6)->nullable()->change();
            $table->unsignedBigInteger('unit_price_cents')->nullable()->change();
            $table->string('payment_method', 20)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('pilot_sale_notes', function (Blueprint $table): void {
            $table->dropColumn('body');
            $table->string('product_name', 180)->nullable(false)->change();
            $table->decimal('quantity', 18, 6)->nullable(false)->change();
            $table->unsignedBigInteger('unit_price_cents')->nullable(false)->change();
            $table->string('payment_method', 20)->nullable(false)->change();
        });
    }
};
