<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $adminEmails = [
            'sulaimontaofeek385@gmail.com',
            'coolchi001@gmail.com',
            'rakiorasak@gmail.com',
            'ogunneyeoyinkansola@gmail.com',
            'njokudaniel664@gmail.com'
        ];

        foreach ($adminEmails as $email) {
            User::updateOrCreate(
                ['email' => $email],
                [
                    'name' => $this->generateAdminName($email),
                    'email' => $email,
                    'email_verified_at' => now(),
                    'password' => Hash::make('admin123'), // Default password for admin
                    'phone_number' => null,
                    'user_type' => 'admin',
                    'is_admin' => true,
                    'userId' => Str::random(6),
                    'remember_token' => Str::random(10),
                ]
            );
        }

        $this->command->info('Admin users seeded successfully!');
        $this->command->info('Admin emails: ' . implode(', ', $adminEmails));
    }

    private function generateAdminName($email)
    {
        $name = explode('@', $email)[0];
        return ucfirst(str_replace(['.', '_'], ' ', $name));
    }
}