<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::table('cars', function (Blueprint $table) {
            $table->string('plate_number')->unique()->nullable();
            $table->enum('type', ['Normal', 'Customized', 'Dealership'])->nullable();
            $table->string('preferred_name')->nullable();
            $table->enum('business_type', ['Co-operate', 'Business'])->nullable();
            $table->string('cac_document')->nullable();
            $table->string('letterhead')->nullable();
            $table->string('means_of_identification')->nullable();
            $table->string('state_of_origin')->nullable();
            $table->string('local_government')->nullable();
            $table->string('blood_group')->nullable();
            $table->string('height')->nullable();
            $table->string('occupation')->nullable();
            $table->string('next_of_kin')->nullable();
            $table->string('next_of_kin_phone')->nullable();
            $table->string('mother_maiden_name')->nullable();
            $table->string('license_years')->nullable();
        });
    }

    public function down()
    {
        Schema::table('cars', function (Blueprint $table) {
            $table->dropColumn([
                'plate_number',
                'type',
                'preferred_name',
                'business_type',
                'cac_document',
                'letterhead',
                'means_of_identification',
                'state_of_origin',
                'local_government',
                'blood_group',
                'height',
                'occupation',
                'next_of_kin',
                'next_of_kin_phone',
                'mother_maiden_name',
                'license_years',
            ]);
        });
    }
}; 