<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('driver_license_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_id');
            $table->decimal('amount', 10, 2);
            $table->unsignedBigInteger('driver_license_id');
            $table->string('status');
            $table->string('reference_code')->nullable();
            $table->string('payment_description')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->json('raw_response')->nullable();
            $table->json('meta_data')->nullable();
            $table->timestamps();

            $table->foreign('driver_license_id')->references('id')->on('drivers_licenses')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_license_transactions');
    }
}; 