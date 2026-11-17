<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 24)->unique();
            $table->string('name', 120);
            $table->string('address', 240)->nullable();
            $table->string('invoice_series', 4)->unique();
            $table->string('receipt_series', 4)->unique();
            $table->unsignedInteger('next_invoice_beta')->default(1);
            $table->unsignedInteger('next_receipt_beta')->default(1);
            $table->unsignedInteger('next_invoice_production')->default(1);
            $table->unsignedInteger('next_receipt_production')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $now = now();
        $settings = DB::table('sunat_settings')->where('id', 1)->first();
        $invoiceA = $settings?->invoice_series ?: 'F001';
        $receiptA = $settings?->receipt_series ?: 'B001';
        $usedSeries = DB::table('sale_documents')->pluck('series')->all();
        $nextSeries = static function (string $prefix, string $first) use ($usedSeries): string {
            for ($number = 2; $number <= 999; $number++) {
                $candidate = $prefix.str_pad((string) $number, 3, '0', STR_PAD_LEFT);
                if ($candidate !== $first && ! in_array($candidate, $usedSeries, true)) return $candidate;
            }
            throw new RuntimeException('No hay series libres para la segunda tienda.');
        };
        DB::table('branches')->insert([
            ['code' => 'LIONS_A', 'name' => 'Lions A', 'invoice_series' => $invoiceA, 'receipt_series' => $receiptA,
                'next_invoice_beta' => $settings?->next_invoice_beta ?: 1, 'next_receipt_beta' => $settings?->next_receipt_beta ?: 1,
                'next_invoice_production' => $settings?->next_invoice_production ?: 1, 'next_receipt_production' => $settings?->next_receipt_production ?: 1,
                'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'LIONS_B', 'name' => 'Lions B', 'invoice_series' => $nextSeries('F', $invoiceA), 'receipt_series' => $nextSeries('B', $receiptA),
                'next_invoice_beta' => 1, 'next_receipt_beta' => 1, 'next_invoice_production' => 1, 'next_receipt_production' => 1,
                'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
        $a = (int) DB::table('branches')->where('code', 'LIONS_A')->value('id');
        $b = (int) DB::table('branches')->where('code', 'LIONS_B')->value('id');

        Schema::table('warehouses', function (Blueprint $table): void {
            $table->foreignId('branch_id')->nullable()->after('id')->constrained()->restrictOnDelete();
            $table->index(['branch_id', 'is_active']);
        });
        DB::table('warehouses')->whereNull('branch_id')->update(['branch_id' => $a]);
        $warehouseId = DB::table('warehouses')->insertGetId([
            'branch_id' => $b, 'code' => 'LIONS_B', 'name' => 'Almacén Lions B',
            'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('warehouse_locations')->insert([
            'warehouse_id' => $warehouseId, 'code' => 'DEFAULT', 'name' => 'Ubicación general',
            'is_default' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);

        Schema::create('branch_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['branch_id', 'user_id']);
        });
        foreach (DB::table('users')->where('role', '!=', 'admin')->pluck('id') as $userId) {
            DB::table('branch_user')->insert(['branch_id' => $a, 'user_id' => $userId, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_user');
        Schema::table('warehouses', function (Blueprint $table): void {
            $table->dropForeign(['branch_id']);
            $table->dropIndex(['branch_id', 'is_active']);
            $table->dropColumn('branch_id');
        });
        Schema::dropIfExists('branches');
    }
};
