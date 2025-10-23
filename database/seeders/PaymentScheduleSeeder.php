<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\PaymentSchedule;

class PaymentScheduleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $schedules = [
            [
                'id' => 1,
                'payment_head_id' => 1, // Insurance
                'gateway_id' => 1,
                'revenue_head_id' => 1,
                'amount' => 15000,
            ],
            [
                'id' => 2,
                'payment_head_id' => 2, // Vehicle License
                'gateway_id' => 1,
                'revenue_head_id' => 1,
                'amount' => 4700,
            ],
            [
                'id' => 3,
                'payment_head_id' => 3, // Proof Of Ownership
                'gateway_id' => 1,
                'revenue_head_id' => 1,
                'amount' => 130000,
            ],
            [
                'id' => 4,
                'payment_head_id' => 4, // Road Worthiness
                'gateway_id' => 1,
                'revenue_head_id' => 1,
                'amount' => 15000,
            ],
            [
                'id' => 5,
                'payment_head_id' => 5, // Hackney Permit
                'gateway_id' => 1,
                'revenue_head_id' => 1,
                'amount' => 6000,
            ],
        ];

        foreach ($schedules as $schedule) {
            PaymentSchedule::updateOrCreate(['id' => $schedule['id']], $schedule);
        }
    }
} 