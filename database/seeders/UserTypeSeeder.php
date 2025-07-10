<?php

namespace Database\Seeders;

use App\Models\UserType;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class UserTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $user_types = array(
            ['id' => 1, 'user_type_name' => "Super_admin", 'created_at' => now()],
            ['id' => 2, 'user_type_name' => "Client", 'created_at' => now()],
        );

        foreach ($user_types as $type) {
            UserType::updateOrCreate(['id' => $type['id']],  ['user_type_name' => $type['user_type_name']]);
        }
    }
}
