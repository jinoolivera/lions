<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('role', 24)->default('cashier')->after('password');
        });
        DB::table('users')->where('email', 'admin@lions.com')->update(['role' => 'admin']);

        Schema::table('sale_lines', function (Blueprint $table): void {
            $table->unsignedBigInteger('subtotal_cents')->default(0)->after('unit_price_cents');
            $table->unsignedBigInteger('discount_cents')->default(0)->after('subtotal_cents');
            $table->foreignId('discount_approved_by_user_id')->nullable()->after('discount_cents')->constrained('users')->nullOnDelete();
        });
        DB::table('sale_lines')->update(['subtotal_cents' => DB::raw('line_total_cents')]);
    }

    public function down(): void
    {
        Schema::table('sale_lines', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('discount_approved_by_user_id');
            $table->dropColumn(['subtotal_cents', 'discount_cents']);
        });
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('role'));
    }
};
