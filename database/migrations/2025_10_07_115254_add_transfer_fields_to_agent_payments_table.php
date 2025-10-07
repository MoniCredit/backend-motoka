<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('agent_payments', function (Blueprint $table) {
            $table->string('transfer_reference')->nullable()->after('notes');
            $table->string('paystack_transfer_id')->nullable()->after('transfer_reference');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agent_payments', function (Blueprint $table) {
            $table->dropColumn(['transfer_reference', 'paystack_transfer_id']);
        });
    }
};