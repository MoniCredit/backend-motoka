<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BankSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
  public function run()
	{
		$payment_banks = array(
			array('id' => '1', 'bank_name' => 'FIRST BANK::011', 'account_name' => 'AWONUGA ABDULQUADRI GBEMILEKE', 'account_no' => '3153680406', 'bank_code' => '011', 'status' => 'active', 'created_at' => '2025-06-28 16:45:35', 'updated_at' => '2025-06-28 16:45:35')
		);

		foreach ($payment_banks as $bank) {
            \App\Models\Bank::updateOrCreate(['id' => $bank['id']], $bank);
        }
	}
}
