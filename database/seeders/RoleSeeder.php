<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $roles = array(
            array(
                'name' => "user",
                'description' => 'user',
                'guard_name' => 'web',
                'is_sys' => 'yes'
            ),
            array(
                'name' => "super_admin",
                'description' => 'Super Admin',
                'guard_name' => 'web',
                'is_sys' => 'yes'
            ),
            array(
                'name' => "guest",
                'description' => 'Guest',
                'guard_name' => 'web',
                'is_sys' => 'yes'
            ),

        );
        foreach ($roles as $role) {
            $user = Role::updateOrCreate($role);
        }
    }
}
