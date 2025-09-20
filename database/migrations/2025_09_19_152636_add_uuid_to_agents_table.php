<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Check if uuid column already exists
        if (!Schema::hasColumn('agents', 'uuid')) {
            Schema::table('agents', function (Blueprint $table) {
                $table->uuid('uuid')->nullable()->after('id');
            });
        }

        // Generate UUIDs for existing records that don't have them
        $agents = \App\Models\Agent::whereNull('uuid')->get();
        foreach ($agents as $agent) {
            $agent->uuid = Str::uuid();
            $agent->save();
        }

        // Now make it unique and not null (if not already)
        try {
            Schema::table('agents', function (Blueprint $table) {
                $table->uuid('uuid')->unique()->nullable(false)->change();
            });
        } catch (\Exception $e) {
            // Column might already be unique, continue
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn('uuid');
        });
    }
};