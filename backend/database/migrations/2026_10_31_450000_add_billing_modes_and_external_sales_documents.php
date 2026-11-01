<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sunat_settings', function (Blueprint $table): void {
            $table->string('billing_mode', 20)->default('internal')->after('environment');
        });

        Schema::create('sale_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('document_type', 20);
            $table->string('series', 4);
            $table->string('number', 8);
            $table->date('issue_date');
            $table->unsignedBigInteger('total_cents');
            $table->string('issued_via', 20);
            $table->string('status', 24)->default('registered_external');
            $table->timestamps();
            $table->unique(['series', 'number']);
            $table->unique('sale_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_documents');
        Schema::table('sunat_settings', fn (Blueprint $table) => $table->dropColumn('billing_mode'));
    }
};
