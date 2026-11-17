<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->string('requested_document_type', 20)->nullable();
        });

        Schema::table('sale_documents', function (Blueprint $table): void {
            $table->string('pdf_path')->nullable();
        });

        Schema::create('sale_document_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sale_document_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('adjustment_type', 20);
            $table->string('series', 4);
            $table->string('number', 8);
            $table->date('issue_date');
            $table->unsignedBigInteger('total_cents');
            $table->string('issued_via', 20);
            $table->string('status', 24)->default('registered_external');
            $table->string('reason', 500);
            $table->string('pdf_path')->nullable();
            $table->timestamps();
            $table->unique(['series', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_document_adjustments');
        Schema::table('sale_documents', fn (Blueprint $table) => $table->dropColumn('pdf_path'));
        Schema::table('sales', fn (Blueprint $table) => $table->dropColumn('requested_document_type'));
    }
};
