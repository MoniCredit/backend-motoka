<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\DriverLicensePayment;

class DriverLicensePaymentSeeder extends Seeder
{
    public function run(): void
    {
        $options = [
            [
                'type' => 'new',
                'name' => "Driver's License (New)",
                'amount' => 200,
                'revenue_head_code' => 'REV68dff2878cb81', 
            ],
            [
                'type' => 'renew',
                'name' => "Driver's License Renewal",
                'amount' => 400,
                'revenue_head_code' => 'REV68dff2878cb81',
            ],
            [
                'type' => 'lost_damaged',
                'name' => "Driver's License Replacement",
                'amount' => 250,
                'revenue_head_code' => 'REV68dff2878cb81',
            ],
        ];
        foreach ($options as $option) {
            DriverLicensePayment::updateOrCreate(['type' => $option['type']], $option);
        }
    }
} 