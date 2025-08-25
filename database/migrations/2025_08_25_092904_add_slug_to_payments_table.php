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
        Schema::table('payments', function (Blueprint $table) {
            if (!Schema::hasColumn('payments', 'slug')) {
                $table->uuid('slug')->nullable()->after('user_id');
            }
        });

        // Backfill slug with UUIDs if null
        $payments = DB::table('payments')->whereNull('slug')->get();
        foreach ($payments as $p) {
            DB::table('payments')->where('id', $p->id)->update(['slug' => Str::uuid()]);
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->uuid('slug')->nullable(false)->change();
            $table->unique('slug');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasColumn('payments', 'slug')) {
                $table->dropUnique(['slug']);
                $table->dropColumn('slug');
            }
        });
    }
};
