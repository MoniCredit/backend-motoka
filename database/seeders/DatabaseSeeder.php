<?php

namespace Database\Seeders;

use App\Models\User;
use Carbon\Carbon;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            UserTypeSeeder::class,
            CarTypeSeeder::class,
            CountrySeeder::class,
            RoleSeeder::class,
            PaymentHeadSeeder::class,
            GatewaySeeder::class,
            BankSeeder::class,
            RevenueHeadSeeder::class,
            PaymentScheduleSeeder::class,
            DeliveryFeeSeeder::class,
        ]);

        $admin = array(
            array(
                'userId' => 123456,
                'user_type_id' => 1,
                'email' => "dev@motoka.net",
                'phone_number' => "08169453935",
                'name' => 'Super Admin',
                'image' =>
                "https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcSyudBxqf1sdD2e3L4nI3nqsMt1_tceOyuZ7A&usqp=CAU",
                'password' => bcrypt('12345'),
                'email_verified_at' => Carbon::now(),
            )
        );


        foreach ($admin as $value) {
            User::updateOrCreate(
                ['userId' => $value['userId']], // unique key
                [
                    'user_type_id' => $value['user_type_id'],
                    'email' => $value['email'],
                    'phone_number' => $value['phone_number'],
                    'name' => $value['name'],
                    'image' => $value['image'],
                    'password' => $value['password'],
                    'email_verified_at' => $value['email_verified_at'],
                ]
            );
        }
    }
}
