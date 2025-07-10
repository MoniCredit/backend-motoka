<?php

namespace Database\Seeders;

use App\Models\CarType;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class CarTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $car_types = [
            ['make' => 'Toyota', 'model' => 'Camry', 'year' => '2023', 'body_type' => 'Sedan'],
            ['make' => 'Toyota', 'model' => 'Corolla', 'year' => '2023', 'body_type' => 'Sedan'],
            ['make' => 'Toyota', 'model' => 'RAV4', 'year' => '2023', 'body_type' => 'SUV'],
            ['make' => 'Toyota', 'model' => 'Highlander', 'year' => '2023', 'body_type' => 'SUV'],
            ['make' => 'Honda', 'model' => 'Accord', 'year' => '2023', 'body_type' => 'Sedan'],
            ['make' => 'Honda', 'model' => 'Civic', 'year' => '2023', 'body_type' => 'Sedan'],
            ['make' => 'Honda', 'model' => 'CR-V', 'year' => '2023', 'body_type' => 'SUV'],
            ['make' => 'Honda', 'model' => 'Pilot', 'year' => '2023', 'body_type' => 'SUV'],
            ['make' => 'Nissan', 'model' => 'Altima', 'year' => '2023', 'body_type' => 'Sedan'],
            ['make' => 'Nissan', 'model' => 'Sentra', 'year' => '2023', 'body_type' => 'Sedan'],
            ['make' => 'Nissan', 'model' => 'Rogue', 'year' => '2023', 'body_type' => 'SUV'],
            ['make' => 'Nissan', 'model' => 'Pathfinder', 'year' => '2023', 'body_type' => 'SUV'],
            ['make' => 'Ford', 'model' => 'Fusion', 'year' => '2023', 'body_type' => 'Sedan'],
            ['make' => 'Ford', 'model' => 'Mustang', 'year' => '2023', 'body_type' => 'Coupe'],
            ['make' => 'Ford', 'model' => 'Escape', 'year' => '2023', 'body_type' => 'SUV'],
            ['make' => 'Ford', 'model' => 'Explorer', 'year' => '2023', 'body_type' => 'SUV'],
            ['make' => 'Chevrolet', 'model' => 'Malibu', 'year' => '2023', 'body_type' => 'Sedan'],
            ['make' => 'Chevrolet', 'model' => 'Impala', 'year' => '2023', 'body_type' => 'Sedan'],
            ['make' => 'Chevrolet', 'model' => 'Equinox', 'year' => '2023', 'body_type' => 'SUV'],
            ['make' => 'Chevrolet', 'model' => 'Traverse', 'year' => '2023', 'body_type' => 'SUV'],
        ];

        foreach ($car_types as $car_type) {
            CarType::updateOrCreate(
                ['make' => $car_type['make'], 'model' => $car_type['model']],
                $car_type
            );
        }
    }
}
