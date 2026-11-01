<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_guides', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('delivery_shipment_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('series', 4);
            $table->string('number', 8);
            $table->date('issue_date');
            $table->string('issued_via', 24);
            $table->string('status', 24)->default('registered_external');
            $table->timestamps();
            $table->unique(['series', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_guides');
    }
};
