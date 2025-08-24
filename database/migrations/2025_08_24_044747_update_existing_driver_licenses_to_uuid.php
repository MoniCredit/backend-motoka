<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Drop foreign key constraint first
        Schema::table('driver_license_transactions', function (Blueprint $table) {
            $table->dropForeign(['driver_license_id']);
        });

        // Get all existing driver licenses
        $licenses = DB::table('drivers_licenses')->get();
        $idMapping = []; // Store mapping of old ID to new UUID

        foreach ($licenses as $license) {
            $newUuid = Str::uuid();
            $idMapping[$license->id] = $newUuid;

            // Update the driver license with new UUID
            DB::table('drivers_licenses')
                ->where('id', $license->id)
                ->update(['id' => $newUuid]);
        }

        // Update driver_license_transactions table to use new UUIDs
        foreach ($idMapping as $oldId => $newUuid) {
            DB::table('driver_license_transactions')
                ->where('driver_license_id', $oldId)
                ->update(['driver_license_id' => $newUuid]);
        }

        // Re-add foreign key constraint
        Schema::table('driver_license_transactions', function (Blueprint $table) {
            $table->foreign('driver_license_id')->references('id')->on('drivers_licenses')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // This migration doesn't need a down method as it's just updating data
        // The actual rollback would be handled by the previous migration
    }
};
