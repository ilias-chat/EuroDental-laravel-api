<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CODE = 'website_products_visibility';

    public function up(): void
    {
        $now = now();

        if (! DB::table('permissions')->where('code', self::CODE)->exists()) {
            DB::table('permissions')->insert([
                'code' => self::CODE,
                'label' => 'Gérer la visibilité et l\'affichage du prix des produits',
                'group' => 'Site Web',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $permissionId = DB::table('permissions')->where('code', self::CODE)->value('id');
        if (! $permissionId) {
            return;
        }

        $profileIds = DB::table('profile_permission')
            ->join('permissions', 'permissions.id', '=', 'profile_permission.permission_id')
            ->where('permissions.code', 'website_products_manage')
            ->pluck('profile_permission.profile_id')
            ->unique()
            ->push(1)
            ->unique();

        foreach ($profileIds as $profileId) {
            if (! DB::table('profile_permission')->where('profile_id', $profileId)->where('permission_id', $permissionId)->exists()) {
                DB::table('profile_permission')->insert([
                    'profile_id' => $profileId,
                    'permission_id' => $permissionId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('code', self::CODE)->value('id');

        if ($permissionId) {
            DB::table('profile_permission')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }
    }
};
