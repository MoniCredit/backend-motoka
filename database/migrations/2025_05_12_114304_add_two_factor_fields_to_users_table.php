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
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->boolean('two_factor_enabled')->default(false);
            $table->enum('two_factor_type', ['email', 'google'])->nullable();
            $table->string('two_factor_email_code')->nullable();
            $table->timestamp('two_factor_email_expires_at')->nullable();
            $table->string('two_factor_login_token', 100)->nullable();
            $table->timestamp('two_factor_login_expires_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'two_factor_secret',
                'two_factor_recovery_codes',
                'two_factor_enabled',
                'two_factor_type',
                'two_factor_email_code',
                'two_factor_email_expires_at',
            ]);
        });
    }
};
