<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\State;
use App\Models\Lga;
use Illuminate\Support\Facades\DB;

class StatesLgasSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 🔹 Temporarily disable foreign key checks
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');

        // 🔹 Truncate both tables
        Lga::truncate();
        State::truncate();

        // 🔹 Re-enable foreign key checks
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $states = [
            ['state_name' => 'Lagos', 'status' => 'active'],
            ['state_name' => 'Abia', 'status' => 'active'],
            ['state_name' => 'Adamawa', 'status' => 'active'],
            ['state_name' => 'Akwa Ibom', 'status' => 'active'],
            ['state_name' => 'Anambra', 'status' => 'active'],
            ['state_name' => 'Bauchi', 'status' => 'active'],
            ['state_name' => 'Bayelsa', 'status' => 'active'],
            ['state_name' => 'Benue', 'status' => 'active'],
            ['state_name' => 'Borno', 'status' => 'active'],
            ['state_name' => 'Cross River', 'status' => 'active'],
            ['state_name' => 'Delta', 'status' => 'active'],
            ['state_name' => 'Ebonyi', 'status' => 'active'],
            ['state_name' => 'Edo', 'status' => 'active'],
            ['state_name' => 'Ekiti', 'status' => 'active'],
            ['state_name' => 'Enugu', 'status' => 'active'],
            ['state_name' => 'Gombe', 'status' => 'active'],
            ['state_name' => 'Imo', 'status' => 'active'],
            ['state_name' => 'Jigawa', 'status' => 'active'],
            ['state_name' => 'Kaduna', 'status' => 'active'],
            ['state_name' => 'Kano', 'status' => 'active'],
            ['state_name' => 'Katsina', 'status' => 'active'],
            ['state_name' => 'Kebbi', 'status' => 'active'],
            ['state_name' => 'Kogi', 'status' => 'active'],
            ['state_name' => 'Kwara', 'status' => 'active'],
            ['state_name' => 'Nasarawa', 'status' => 'active'],
            ['state_name' => 'Niger', 'status' => 'active'],
            ['state_name' => 'Ogun', 'status' => 'active'],
            ['state_name' => 'Ondo', 'status' => 'active'],
            ['state_name' => 'Osun', 'status' => 'active'],
            ['state_name' => 'Oyo', 'status' => 'active'],
            ['state_name' => 'Plateau', 'status' => 'active'],
            ['state_name' => 'Rivers', 'status' => 'active'],
            ['state_name' => 'Sokoto', 'status' => 'active'],
            ['state_name' => 'Taraba', 'status' => 'active'],
            ['state_name' => 'Yobe', 'status' => 'active'],
            ['state_name' => 'Zamfara', 'status' => 'active'],
            ['state_name' => 'FCT', 'status' => 'active'],
        ];

        foreach ($states as $stateData) {
            $state = State::create($stateData);
            
            $lgas = $this->getLgasForState($stateData['state_name']);
            foreach ($lgas as $lgaName) {
                Lga::create([
                    'lga_name' => $lgaName,
                    'state_id' => $state->id,
                    'status' => 'active'
                ]);
            }
        }
    }

    private function getLgasForState($stateName)
    {
        $lgas = [
            'Lagos' => ['Ikeja', 'Eti-Osa', 'Lagos Island', 'Lagos Mainland', 'Surulere', 'Mushin', 'Oshodi-Isolo', 'Shomolu', 'Kosofe', 'Ajeromi-Ifelodun', 'Amuwo-Odofin', 'Apapa', 'Badagry', 'Epe', 'Ibeju-Lekki', 'Ifako-Ijaiye', 'Ikorodu', 'Mushin', 'Ojo', 'Ojodu', 'Orile-Agege', 'Somolu', 'Surulere'],
            'Abia' => ['Aba North', 'Aba South', 'Arochukwu', 'Bende', 'Ikwuano', 'Isiala Ngwa North', 'Isiala Ngwa South', 'Isuikwuato', 'Obi Ngwa', 'Ohafia', 'Osisioma', 'Ugwunagbo', 'Ukwa East', 'Ukwa West', 'Umuahia North', 'Umuahia South', 'Umu Nneochi'],
            'Ogun' => ['Abeokuta North', 'Abeokuta South', 'Ado-Odo/Ota', 'Egbado North', 'Egbado South', 'Ewekoro', 'Ifo', 'Ijebu East', 'Ijebu North', 'Ijebu North East', 'Ijebu Ode', 'Ikenne', 'Imeko Afon', 'Ipokia', 'Obafemi Owode', 'Odeda', 'Odogbolu', 'Ogun Waterside', 'Remo North', 'Shagamu'],
            'Ondo' => ['Akoko North-East', 'Akoko North-West', 'Akoko South-West', 'Akoko South-East', 'Akure North', 'Akure South', 'Ese Odo', 'Idanre', 'Ifedore', 'Ilaje', 'Ile Oluji/Okeigbo', 'Irele', 'Odigbo', 'Okitipupa', 'Ondo East', 'Ondo West', 'Ose', 'Owo'],
            'Osun' => ['Atakunmosa East', 'Atakunmosa West', 'Aiyedaade', 'Aiyedire', 'Boluwaduro', 'Boripe', 'Ede North', 'Ede South', 'Ife Central', 'Ife East', 'Ife North', 'Ife South', 'Egbedore', 'Ejigbo', 'Ifedayo', 'Ifelodun', 'Ila', 'Ilesa East', 'Ilesa West', 'Irepodun', 'Irewole', 'Isokan', 'Iwo', 'Obokun', 'Odo Otin', 'Ola Oluwa', 'Olorunda', 'Oriade', 'Orolu', 'Osogbo'],
            'Oyo' => ['Afijio', 'Akinyele', 'Atiba', 'Atisbo', 'Egbeda', 'Ibadan North', 'Ibadan North-East', 'Ibadan North-West', 'Ibadan South-East', 'Ibadan South-West', 'Ibarapa Central', 'Ibarapa East', 'Ibarapa North', 'Ido', 'Irepo', 'Iseyin', 'Itesiwaju', 'Iwajowa', 'Kajola', 'Lagelu', 'Ogbomoso North', 'Ogbomoso South', 'Ogo Oluwa', 'Olorunsogo', 'Oluyole', 'Ona Ara', 'Orelope', 'Ori Ire', 'Oyo', 'Oyo East', 'Saki East', 'Saki West', 'Surulere'],
        ];

        return $lgas[$stateName] ?? ['Central', 'North', 'South', 'East', 'West'];
    }
}
