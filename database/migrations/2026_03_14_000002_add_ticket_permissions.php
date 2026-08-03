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
        Permission::create([
            'code' => 'tickets_create',
            'label' => 'Créer des tickets',
        ]);

        Permission::create([
            'code' => 'tickets_manage',
            'label' => 'Gérer et répondre à tous les tickets',
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Permission::where('code', 'tickets_create')->delete();
        Permission::where('code', 'tickets_manage')->delete();
    }
};
