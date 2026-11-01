<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sunat_settings', function (Blueprint $table): void {
            $table->unsignedInteger('next_invoice_beta')->default(1);
            $table->unsignedInteger('next_receipt_beta')->default(1);
            $table->unsignedInteger('next_invoice_production')->default(1);
            $table->unsignedInteger('next_receipt_production')->default(1);
            $table->decimal('igv_rate', 5, 2)->default(18.00);
        });

        Schema::table('sale_documents', function (Blueprint $table): void {
            $table->string('environment', 12)->default('external')->after('issued_via');
            $table->string('xml_path')->nullable();
            $table->string('cdr_path')->nullable();
            $table->string('sunat_response_code', 20)->nullable();
            $table->text('sunat_message')->nullable();
            $table->string('sunat_ticket', 80)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->dropForeign('sale_documents_sale_id_foreign');
            $table->dropUnique('sale_documents_sale_id_unique');
            $table->dropUnique('sale_documents_series_number_unique');
            $table->unique(['sale_id', 'environment'], 'sale_documents_sale_environment_unique');
            $table->unique(['environment', 'series', 'number'], 'sale_documents_environment_series_number_unique');
            $table->index(['environment', 'status']);
            $table->foreign('sale_id')->references('id')->on('sales')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sale_documents', function (Blueprint $table): void {
            $table->dropIndex(['environment', 'status']);
            $table->dropUnique('sale_documents_sale_environment_unique');
            $table->dropUnique('sale_documents_environment_series_number_unique');
            $table->dropForeign('sale_documents_sale_id_foreign');
            $table->unique('sale_id');
            $table->unique(['series', 'number']);
            $table->dropColumn(['environment', 'xml_path', 'cdr_path', 'sunat_response_code', 'sunat_message', 'sunat_ticket', 'sent_at']);
            $table->foreign('sale_id')->references('id')->on('sales')->restrictOnDelete();
        });
        Schema::table('sunat_settings', function (Blueprint $table): void {
            $table->dropColumn(['next_invoice_beta', 'next_receipt_beta', 'next_invoice_production', 'next_receipt_production', 'igv_rate']);
        });
    }
};
