<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PERMISSIONS = [
        ['code' => 'website_creatives_manage', 'label' => 'Gérer les créatifs du site (accueil, contact, à propos)', 'group' => 'Site Web'],
        ['code' => 'website_products_manage', 'label' => 'Gérer les produits du site web', 'group' => 'Site Web'],
        ['code' => 'website_flipbooks_manage', 'label' => 'Gérer les publications du site', 'group' => 'Site Web'],
        ['code' => 'website_settings_manage', 'label' => 'Gérer les paramètres du site web', 'group' => 'Site Web'],
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::PERMISSIONS as $perm) {
            $exists = DB::table('permissions')->where('code', $perm['code'])->exists();
            if (! $exists) {
                DB::table('permissions')->insert([
                    'code' => $perm['code'],
                    'label' => $perm['label'],
                    'group' => $perm['group'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $newIds = DB::table('permissions')
            ->whereIn('code', array_column(self::PERMISSIONS, 'code'))
            ->pluck('id');

        foreach ($newIds as $permissionId) {
            if (! DB::table('profile_permission')->where('profile_id', 1)->where('permission_id', $permissionId)->exists()) {
                DB::table('profile_permission')->insert([
                    'profile_id' => 1,
                    'permission_id' => $permissionId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $legacyCodes = ['webshop_manage', 'website_home_manage', 'website_contact_manage', 'website_about_manage'];
        $legacyIds = DB::table('permissions')->whereIn('code', $legacyCodes)->pluck('id');

        if ($legacyIds->isNotEmpty()) {
            $profileIds = DB::table('profile_permission')
                ->whereIn('permission_id', $legacyIds)
                ->pluck('profile_id')
                ->unique();

            $creativesId = DB::table('permissions')->where('code', 'website_creatives_manage')->value('id');

            foreach ($profileIds as $profileId) {
                if ($creativesId && ! DB::table('profile_permission')->where('profile_id', $profileId)->where('permission_id', $creativesId)->exists()) {
                    DB::table('profile_permission')->insert([
                        'profile_id' => $profileId,
                        'permission_id' => $creativesId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                foreach ($newIds as $permissionId) {
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
        }
    }

    public function down(): void
    {
        $codes = array_column(self::PERMISSIONS, 'code');
        $ids = DB::table('permissions')->whereIn('code', $codes)->pluck('id');

        DB::table('profile_permission')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('code', $codes)->delete();
    }
};
