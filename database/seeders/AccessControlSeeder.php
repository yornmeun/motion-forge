<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class AccessControlSeeder extends Seeder
{
    public const PERMISSIONS = [
        'projects.create' => 'Create projects',
        'packages.approve' => 'Approve production packages',
        'credentials.manage' => 'Manage AI credentials',
        'render.start' => 'Generate and render',
        'usage.view' => 'View usage and costs',
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $slug => $name) {
            Permission::updateOrCreate(['slug' => $slug], ['name' => $name]);
        }
        $roles = [
            'admin' => ['Administrator', array_keys(self::PERMISSIONS)],
            'producer' => ['Producer', array_keys(self::PERMISSIONS)],
            'viewer' => ['Viewer', ['usage.view']],
        ];
        foreach ($roles as $slug => [$name, $perms]) {
            $role = Role::updateOrCreate(['slug' => $slug], ['name' => $name]);
            $role->permissions()->sync(Permission::whereIn('slug', $perms)->pluck('id'));
        }
    }
}
