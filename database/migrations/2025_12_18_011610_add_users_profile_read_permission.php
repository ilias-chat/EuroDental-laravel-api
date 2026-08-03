<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\Permission;

return new class extends Migration
{
    public function up(): void
    {
        Permission::create([
            'code' => 'users_profile_read',
            'label' => 'Can view user profiles',
            'group' => 'users'
        ]);
    }

    public function down(): void
    {
        Permission::where('code', 'users_profile_read')->delete();
    }
};

