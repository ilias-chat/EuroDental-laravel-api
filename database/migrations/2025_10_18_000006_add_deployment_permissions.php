<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add deployment permissions to rights table
        DB::table('rights')->insert([
            [
                'code' => 'deployment_read',
                'name' => 'Voir les déploiements',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'deployment_write',
                'name' => 'Créer/Modifier les déploiements',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('rights')->whereIn('code', ['deployment_read', 'deployment_write'])->delete();
    }
};

