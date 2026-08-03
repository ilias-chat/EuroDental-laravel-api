<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('source', 20)->nullable()->after('created_by');
            $table->foreignId('confirmed_by')->nullable()->after('source')->constrained('users')->onDelete('set null');
        });

        // Backfill existing orders as admin-created
        \DB::table('orders')->whereNull('source')->update(['source' => 'admin']);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['confirmed_by']);
            $table->dropColumn(['source', 'confirmed_by']);
        });
    }
};
