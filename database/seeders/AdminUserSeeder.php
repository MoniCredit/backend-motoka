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
            'sulaimontaofeek384@gmail.com', 
            'coolchi001@gmail.com',
            'rakiorasak@gmail.com',
            'ogunneyeoyinkansola@gmail.com',
            'njokudaniel664@gmail.com'
        ];

        // First, remove admin privileges from users not in the current list
        User::where('is_admin', 1)
            ->whereNotIn('email', $adminEmails)
            ->update(['is_admin' => 0]);

        // Then create/update admin users
        foreach ($adminEmails as $email) {
            User::updateOrCreate(
                ['email' => $email],
                [
                    'name' => $this->generateAdminName($email),
                    'email' => $email,
                    'email_verified_at' => now(),
                    'password' => Hash::make('admin123'), 
                    'phone_number' => null,
                    'user_type' => 'admin',
                    'is_admin' => 1,
                    'userId' => Str::random(6),
                    'remember_token' => Str::random(10),
                ]
            );
        }

        $this->command->info('Admin users seeded successfully!');
        $this->command->info('Admin emails: ' . implode(', ', $adminEmails));
        
        // Show which users had admin privileges removed
        $removedAdmins = User::where('is_admin', 0)
            ->whereIn('email', ['sulaimontaofeek384@gmail.com'])
            ->pluck('email')
            ->toArray();
            
        if (!empty($removedAdmins)) {
            $this->command->warn('Admin privileges removed from: ' . implode(', ', $removedAdmins));
        }
    }

    private function generateAdminName($email)
    {
        $name = explode('@', $email)[0];
        return ucfirst(str_replace(['.', '_'], ' ', $name));
    }
}