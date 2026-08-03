<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\Permission;

return new class extends Migration
{
    public function up(): void
    {
        Permission::create([
            'code' => 'user_flags',
            'label' => 'Gérer les drapeaux performance utilisateur (vert/rouge)',
        ]);
    }

    public function down(): void
    {
        Permission::where('code', 'user_flags')->delete();
    }
};
