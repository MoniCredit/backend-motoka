<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // First, get all duplicate states (keeping the first occurrence)
        $duplicateStates = DB::select("
            SELECT s1.id, s1.state_name, s2.id as keep_id
            FROM states s1
            INNER JOIN states s2 
            WHERE s1.id > s2.id 
            AND s1.state_name = s2.state_name
        ");

        // Update LGAs to point to the kept state before deleting duplicates
        foreach ($duplicateStates as $duplicate) {
            // Update LGAs to point to the state we're keeping
            DB::table('lgas')
                ->where('state_id', $duplicate->id)
                ->update(['state_id' => $duplicate->keep_id]);
            
            // Update orders to point to the state we're keeping
            DB::table('orders')
                ->where('state', $duplicate->id)
                ->update(['state' => $duplicate->keep_id]);
            
            // Now delete the duplicate state
            DB::table('states')->where('id', $duplicate->id)->delete();
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // This migration cannot be reversed as it deletes data
        // If you need to restore, you would need to re-run the StatesLgasSeeder
    }
};