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
        // First, let's clean up any remaining duplicates more thoroughly
        // Handle FCT duplicates specifically
        $fctStates = DB::table('states')
            ->whereIn('state_name', ['F.C.T', 'FCT', 'Federal Capital Territory'])
            ->orderBy('id')
            ->get();
        
        if ($fctStates->count() > 1) {
            $keepFct = $fctStates->first();
            
            // Update all references to point to the first FCT entry
            foreach ($fctStates->skip(1) as $duplicate) {
                // Update LGAs
                DB::table('lgas')
                    ->where('state_id', $duplicate->id)
                    ->update(['state_id' => $keepFct->id]);
                
                // Update orders
                DB::table('orders')
                    ->where('state', $duplicate->id)
                    ->update(['state' => $keepFct->id]);
                
                // Delete duplicate
                DB::table('states')->where('id', $duplicate->id)->delete();
            }
            
            // Update the kept FCT to have a consistent name
            DB::table('states')
                ->where('id', $keepFct->id)
                ->update(['state_name' => 'FCT']);
        }
        
        // Clean up any other remaining duplicates
        $duplicates = DB::select("
            SELECT state_name, MIN(id) as keep_id, GROUP_CONCAT(id) as all_ids
            FROM states 
            GROUP BY state_name 
            HAVING COUNT(*) > 1
        ");
        
        foreach ($duplicates as $duplicate) {
            $ids = explode(',', $duplicate->all_ids);
            $keepId = $duplicate->keep_id;
            
            foreach ($ids as $id) {
                if ($id != $keepId) {
                    // Update LGAs
                    DB::table('lgas')
                        ->where('state_id', $id)
                        ->update(['state_id' => $keepId]);
                    
                    // Update orders
                    DB::table('orders')
                        ->where('state', $id)
                        ->update(['state' => $keepId]);
                    
                    // Delete duplicate
                    DB::table('states')->where('id', $id)->delete();
                }
            }
        }
        
        // Add unique constraint to prevent future duplicates
        Schema::table('states', function (Blueprint $table) {
            $table->unique('state_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('states', function (Blueprint $table) {
            $table->dropUnique(['state_name']);
        });
    }
};