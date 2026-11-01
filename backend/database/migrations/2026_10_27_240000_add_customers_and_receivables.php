<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->string('tax_id', 20)->nullable()->unique();
            $table->string('name', 180)->index();
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->text('address')->nullable();
            $table->unsignedBigInteger('credit_limit_cents')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('sales', function (Blueprint $table): void {
            $table->foreignId('customer_id')->nullable()->after('user_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('paid_cents')->default(0)->after('total_cents');
        });
        Schema::table('sale_payments', function (Blueprint $table): void {
            $table->foreignId('cash_session_id')->nullable()->after('sale_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->after('cash_session_id')->constrained()->nullOnDelete();
            $table->string('kind', 16)->default('sale')->after('user_id');
            $table->string('operation_key', 100)->nullable()->unique()->after('reference');
            $table->char('payload_hash', 64)->nullable()->after('operation_key');
        });
    }

    public function down(): void
    {
        Schema::table('sale_payments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('user_id');
            $table->dropConstrainedForeignId('cash_session_id');
            $table->dropUnique(['operation_key']);
            $table->dropColumn(['kind', 'operation_key', 'payload_hash']);
        });
        Schema::table('sales', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('customer_id');
            $table->dropColumn('paid_cents');
        });
        Schema::dropIfExists('customers');
    }
};
