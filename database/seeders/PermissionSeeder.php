<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Permission;

class PermissionSeeder extends Seeder
{
    public function run()
    {
        $permissions = [
            ['code' => 'products_read', 'label' => 'Can view products'],
            ['code' => 'products_write', 'label' => 'Can manage products'],
            ['code' => 'clients_read', 'label' => 'Can view clients'],
            ['code' => 'clients_write', 'label' => 'Can manage clients'],
            ['code' => 'invoices_read', 'label' => 'Can view invoices'],
            ['code' => 'invoices_write', 'label' => 'Can manage invoices'],
            ['code' => 'tasks_read', 'label' => 'Can view tasks'],
            ['code' => 'tasks_write', 'label' => 'Can manage tasks'],
            ['code' => 'users_read', 'label' => 'Can view users'],
            ['code' => 'users_write', 'label' => 'Can manage users'],
            ['code' => 'users_profile_read', 'label' => 'Can view user profiles'],
            ['code' => 'manage_vacations', 'label' => 'Can manage vacation requests'],
            ['code' => 'manage_task_types', 'label' => 'Can manage task types'],
            ['code' => 'suivi_read', 'label' => 'Can view user tracking'],
        ];

        foreach ($permissions as $perm) {
            Permission::create($perm);
        }

        Permission::firstOrCreate(
            ['code' => 'user_flags'],
            ['label' => 'Gérer les drapeaux performance utilisateur (vert/rouge)']
        );
    }
}
