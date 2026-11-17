<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_guides', function (Blueprint $table): void {
            $table->string('pdf_path')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('delivery_guides', fn (Blueprint $table) => $table->dropColumn('pdf_path'));
    }
};
