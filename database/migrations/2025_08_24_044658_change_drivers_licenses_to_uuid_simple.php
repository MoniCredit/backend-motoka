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

        // Change the drivers_licenses table ID to UUID
        Schema::table('drivers_licenses', function (Blueprint $table) {
            $table->uuid('id')->change();
        });

        // Change the driver_license_transactions table foreign key to UUID
        Schema::table('driver_license_transactions', function (Blueprint $table) {
            $table->uuid('driver_license_id')->change();
        });

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
        // Drop foreign key constraint first
        Schema::table('driver_license_transactions', function (Blueprint $table) {
            $table->dropForeign(['driver_license_id']);
        });

        // Change the driver_license_transactions table foreign key back to unsignedBigInteger
        Schema::table('driver_license_transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('driver_license_id')->change();
        });

        // Change the drivers_licenses table ID back to auto-increment
        Schema::table('drivers_licenses', function (Blueprint $table) {
            $table->id()->change();
        });

        // Re-add foreign key constraint
        Schema::table('driver_license_transactions', function (Blueprint $table) {
            $table->foreign('driver_license_id')->references('id')->on('drivers_licenses')->onDelete('cascade');
        });
    }
};
