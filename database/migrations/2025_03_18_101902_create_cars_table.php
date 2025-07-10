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
        Schema::create('cars', function (Blueprint $table) {
            $table->id();
            // $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('user_id', 6);
            $table->foreign('user_id')->references('userId')->on('users')->onDelete('cascade');
            $table->string('name_of_owner');
            $table->string('phone_number')->nullable();
            $table->text('address');
            $table->string('vehicle_make');
            $table->string('vehicle_model');
            $table->enum('registration_status', ['registered', 'unregistered'])->default('unregistered');
            $table->string('chasis_no')->nullable();
            $table->string('engine_no')->nullable();
            $table->string('vehicle_year')->nullable();
            $table->string('vehicle_color')->nullable();
            $table->string('registration_no')->nullable();
            $table->date('date_issued')->nullable();
            $table->date('expiry_date')->nullable();
            $table->json('document_images')->nullable();
            $table->enum('status', ['active', 'pending', 'rejected'])->default('pending');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**registration_no
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cars');
    }
};
