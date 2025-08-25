<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drivers_licenses', function (Blueprint $table) {
            if (!Schema::hasColumn('drivers_licenses', 'slug')) {
                $table->uuid('slug')->nullable()->after('id');
            }
        });

        $rows = DB::table('drivers_licenses')->whereNull('slug')->get();
        foreach ($rows as $row) {
            DB::table('drivers_licenses')->where('id', $row->id)->update(['slug' => Str::uuid()]);
        }

        Schema::table('drivers_licenses', function (Blueprint $table) {
            $table->uuid('slug')->nullable(false)->change();
            $table->unique('slug');
        });
    }

    public function down(): void
    {
        Schema::table('drivers_licenses', function (Blueprint $table) {
            if (Schema::hasColumn('drivers_licenses', 'slug')) {
                $table->dropUnique(['slug']);
                $table->dropColumn('slug');
            }
        });
    }
};
