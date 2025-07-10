<?php

namespace Database\Seeders;

use App\Models\PaymentHead;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class PaymentHeadSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $user_types = array(
            ['id' => 1, 'payment_head_name' => "Insurance",'status' => "active", 'created_at' => now()],
            ['id' => 2, 'payment_head_name' => "Vehicle License",'status' => "active", 'created_at' => now()],
            ['id' => 3, 'payment_head_name' => "Proof Of Ownership",'status' => "active", 'created_at' => now()],
            ['id' => 4, 'payment_head_name' => "Road Wortiness",'status' => "active", 'created_at' => now()],
        );

        foreach ($user_types as $type) {
            PaymentHead::updateOrCreate(['id' => $type['id']],  ['payment_head_name' => $type['payment_head_name']],['status' => $type['status']]);
        }
    }
}
