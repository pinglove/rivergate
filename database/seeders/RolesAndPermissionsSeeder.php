<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Права (пример)
        $permissions = [
            'view_logs',
            'manage_users',
        ];

        foreach ($permissions as $p) {
            Permission::findOrCreate($p);
        }

        // Роли
        $admin = Role::findOrCreate('admin');
        $user  = Role::findOrCreate('user');

        // Назначаем права ролям
        $admin->givePermissionTo($permissions);
        // $user по умолчанию без этих прав
    }
}
