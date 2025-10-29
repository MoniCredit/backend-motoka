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
                'type' => 'new_3_years',
                'name' => "Driver's License (New - 3 Years)",
                'amount' => 45000,
                'revenue_head_code' => 'REV68dff2878cb81',
                'license_year' => 3,
            ],
            [
                'type' => 'new_5_years',
                'name' => "Driver's License (New - 5 Years)",
                'amount' => 50000,
                'revenue_head_code' => 'REV68dff2878cb81',
                'license_year' => 5,
            ],
            [
                'type' => 'renew',
                'name' => "Driver's License Renewal",
                'amount' => 400,
                'revenue_head_code' => 'REV68dff2878cb81',
                'license_year' => null,
            ],
            [
                'type' => 'lost_damaged',
                'name' => "Driver's License Replacement",
                'amount' => 250,
                'revenue_head_code' => 'REV68dff2878cb81',
                'license_year' => null,
            ],
        ];
        foreach ($options as $option) {
            DriverLicensePayment::updateOrCreate(['type' => $option['type']], $option);
        }
    }
} 