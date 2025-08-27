<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::table('cars', function (Blueprint $table) {
            if (!Schema::hasColumn('cars', 'plate_number')) {
                $table->string('plate_number')->unique()->nullable();
            }
            if (!Schema::hasColumn('cars', 'type')) {
                $table->enum('type', ['Normal', 'Customized', 'Dealership'])->nullable();
            }
            if (!Schema::hasColumn('cars', 'preferred_name')) {
                $table->string('preferred_name')->nullable();
            }
            if (!Schema::hasColumn('cars', 'business_type')) {
                $table->enum('business_type', ['Co-operate', 'Business'])->nullable();
            }
            if (!Schema::hasColumn('cars', 'cac_document')) {
                $table->string('cac_document')->nullable();
            }
            if (!Schema::hasColumn('cars', 'letterhead')) {
                $table->string('letterhead')->nullable();
            }
            if (!Schema::hasColumn('cars', 'means_of_identification')) {
                $table->string('means_of_identification')->nullable();
            }
            if (!Schema::hasColumn('cars', 'state_of_origin')) {
                $table->string('state_of_origin')->nullable();
            }
            if (!Schema::hasColumn('cars', 'local_government')) {
                $table->string('local_government')->nullable();
            }
            if (!Schema::hasColumn('cars', 'blood_group')) {
                $table->string('blood_group')->nullable();
            }
            if (!Schema::hasColumn('cars', 'height')) {
                $table->string('height')->nullable();
            }
            if (!Schema::hasColumn('cars', 'occupation')) {
                $table->string('occupation')->nullable();
            }
            if (!Schema::hasColumn('cars', 'next_of_kin')) {
                $table->string('next_of_kin')->nullable();
            }
            if (!Schema::hasColumn('cars', 'next_of_kin_phone')) {
                $table->string('next_of_kin_phone')->nullable();
            }
            if (!Schema::hasColumn('cars', 'mother_maiden_name')) {
                $table->string('mother_maiden_name')->nullable();
            }
            if (!Schema::hasColumn('cars', 'license_years')) {
                $table->string('license_years')->nullable();
            }
        });
    }

    public function down()
    {
        Schema::table('cars', function (Blueprint $table) {
            $columns = [
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
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('cars', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
