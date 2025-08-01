<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement("ALTER TABLE drivers_licenses MODIFY COLUMN license_type ENUM('new', 'renew', 'lost_damaged')");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE drivers_licenses MODIFY COLUMN license_type ENUM('new', 'renew')");
    }
}; 