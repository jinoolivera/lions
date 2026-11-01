<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('type', 12);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['name', 'type']);
        });
        $now = now();
        DB::table('finance_categories')->insertOrIgnore([
            ['name' => 'Otros ingresos', 'type' => 'income', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Servicios', 'type' => 'expense', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Alquiler', 'type' => 'expense', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Personal', 'type' => 'expense', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Transporte', 'type' => 'expense', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Marketing', 'type' => 'expense', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Otros egresos operativos', 'type' => 'expense', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Schema::create('financial_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('finance_category_id')->constrained('finance_categories')->restrictOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('cash_session_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 12);
            $table->string('method', 20);
            $table->unsignedBigInteger('amount_cents');
            $table->string('reference', 120)->nullable();
            $table->string('description', 500);
            $table->string('operation_key', 100)->unique();
            $table->char('payload_hash', 64);
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['type', 'occurred_at']);
            $table->index(['warehouse_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_transactions');
        Schema::dropIfExists('finance_categories');
    }
};
