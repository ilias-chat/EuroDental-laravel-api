<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\Permission;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add services permissions
        Permission::create([
            'code' => 'services_read',
            'label' => 'Voir les services'
        ]);

        Permission::create([
            'code' => 'services_write',
            'label' => 'Gérer les services'
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Permission::where('code', 'services_read')->delete();
        Permission::where('code', 'services_write')->delete();
    }
};

