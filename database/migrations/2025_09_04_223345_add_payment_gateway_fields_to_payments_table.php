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
        Schema::table('payments', function (Blueprint $table) {
            $table->string('payment_gateway')->default('monicredit')->after('status');
            $table->string('gateway_reference')->nullable()->after('payment_gateway');
            $table->string('gateway_authorization_url')->nullable()->after('gateway_reference');
            $table->json('gateway_response')->nullable()->after('gateway_authorization_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn([
                'payment_gateway',
                'gateway_reference', 
                'gateway_authorization_url',
                'gateway_response'
            ]);
        });
    }
};
