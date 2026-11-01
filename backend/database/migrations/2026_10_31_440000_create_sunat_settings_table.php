<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sunat_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('ruc', 11)->nullable();
            $table->string('legal_name', 180)->nullable();
            $table->string('trade_name', 180)->nullable();
            $table->string('fiscal_address', 240)->nullable();
            $table->string('ubigeo', 6)->nullable();
            $table->string('environment', 12)->default('beta');
            $table->string('sol_user', 32)->nullable();
            $table->text('sol_password')->nullable();
            $table->string('certificate_path')->nullable();
            $table->string('certificate_name')->nullable();
            $table->text('certificate_password')->nullable();
            $table->string('invoice_series', 4)->default('F001');
            $table->string('receipt_series', 4)->default('B001');
            $table->string('notification_email')->nullable();
            $table->boolean('sending_enabled')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sunat_settings');
    }
};
