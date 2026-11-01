<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_orders', function (Blueprint $table): void {
            $table->string('source', 24)->default('other')->after('status');
            $table->text('delivery_address')->nullable()->after('notes');
            $table->string('delivery_contact', 180)->nullable()->after('delivery_address');
            $table->string('dispatch_responsible', 180)->nullable()->after('delivery_contact');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_orders', function (Blueprint $table): void {
            $table->dropColumn(['source', 'delivery_address', 'delivery_contact', 'dispatch_responsible']);
        });
    }
};
