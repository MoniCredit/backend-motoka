<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\State;
use App\Models\Lga;
use App\Models\DeliveryFee;

class DeliveryFeeSeeder extends Seeder
{
    public function run()
    {
        // Map of state_name => fee
        $stateFees = [
            'Anambra' => 70,
            'Enugu' => 220,
            'Akwa Ibom' => 300,
            'Adamawa' => 210,
            'Abia' => 230,
            'Bauchi' => 240,
            'Bayelsa' => 350,
            'Benue' => 260,
            'Borno' => 270,
            'Cross River' => 320,
            'Delta' => 330,
            'Ebonyi' => 215,
            'Edo' => 280,
            'Ekiti' => 225,
            'Gombe' => 245,
            'Imo' => 235,
            'Jigawa' => 255,
            'Kaduna' => 265,
            'Kano' => 275,
            'Katsina' => 285,
            'Kebbi' => 295,
            'Kogi' => 305,
            'Kwara' => 315,
            'Lagos' => 400,
            'Nasarawa' => 325,
            'Niger' => 335,
            'Ogun' => 600,
            'Ondo' => 345,
            'Osun' => 355,
            'Oyo' => 365,
            'Plateau' => 375,
            'Rivers' => 385,
            'Sokoto' => 395,
            'Taraba' => 405,
            'Yobe' => 415,
            'Zamfara' => 425,
            'F.C.T' => 500,
        ];
        $states = State::all();
        foreach ($states as $state) {
            $lgas = Lga::where('state_id', $state->id)->get();
            $fee = $stateFees[$state->state_name] ?? 200;
            foreach ($lgas as $lga) {
                DeliveryFee::updateOrCreate([
                    'state_id' => $state->id,
                    'lga_id' => $lga->id,
                ], [
                    'fee' => $fee,
                ]);
            }
        }
    }
} 