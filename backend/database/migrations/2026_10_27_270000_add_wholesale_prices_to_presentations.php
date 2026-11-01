<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_presentations', function (Blueprint $table): void {
            $table->unsignedBigInteger('wholesale_price_cents')->nullable()->after('price_cents');
        });
    }

    public function down(): void
    {
        Schema::table('product_presentations', function (Blueprint $table): void {
            $table->dropColumn('wholesale_price_cents');
        });
    }
};
