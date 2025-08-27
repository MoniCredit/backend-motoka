<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('drivers_licenses', 'status')) {
            Schema::table('drivers_licenses', function (Blueprint $table) {
                $table->enum('status', ['unpaid', 'active', 'pending', 'rejected'])
                      ->default('unpaid')
                      ->after('user_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('drivers_licenses', 'status')) {
            Schema::table('drivers_licenses', function (Blueprint $table) {
                $table->dropColumn('status');
            });
        }
    }
};
