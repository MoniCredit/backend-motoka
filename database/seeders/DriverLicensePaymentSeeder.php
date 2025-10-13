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
                'revenue_head_code' => 'REV67505e736a592', // Use the real code
            ],
            [
                'type' => 'renew',
                'name' => "Driver's License Renewal",
                'amount' => 150,
                'revenue_head_code' => 'REV67505e736a592',
            ],
            [
                'type' => 'lost_damaged',
                'name' => "Driver's License Replacement",
                'amount' => 250,
                'revenue_head_code' => 'REV67505e736a592',
            ],
        ];
        foreach ($options as $option) {
            DriverLicensePayment::updateOrCreate(['type' => $option['type']], $option);
        }
    }
} 