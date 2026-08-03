<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->boolean('validated')->default(false)->after('source');
            $table->boolean('handled')->default(false)->after('validated');
        });

        // Backfill: true when created via admin (or legacy null source), false when via webshop
        \DB::table('clients')
            ->where(function ($q) {
                $q->where('source', 'admin')->orWhereNull('source');
            })
            ->update(['validated' => true, 'handled' => true]);

        \DB::table('clients')
            ->where('source', 'webshop')
            ->update(['validated' => false, 'handled' => false]);
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['validated', 'handled']);
        });
    }
};
