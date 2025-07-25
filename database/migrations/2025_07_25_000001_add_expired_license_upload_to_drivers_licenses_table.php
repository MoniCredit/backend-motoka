<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::table('drivers_licenses', function (Blueprint $table) {
            $table->string('expired_license_upload')->nullable()->after('status');
        });
    }

    public function down()
    {
        Schema::table('drivers_licenses', function (Blueprint $table) {
            $table->dropColumn('expired_license_upload');
        });
    }
};
