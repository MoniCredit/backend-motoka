<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Update the ENUM values for the status column
        DB::statement("ALTER TABLE agent_payments MODIFY COLUMN status ENUM('pending', 'paid', 'cancelled', 'manual_payment_required', 'failed', 'reversed') DEFAULT 'pending'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert back to original ENUM values
        DB::statement("ALTER TABLE agent_payments MODIFY COLUMN status ENUM('pending', 'paid', 'cancelled') DEFAULT 'pending'");
    }
};