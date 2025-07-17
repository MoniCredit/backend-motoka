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
            $table->enum('car_type', ['private', 'commercial'])->default('private');
            $table->string('chasis_no')->nullable();
            $table->string('engine_no')->nullable();
            $table->string('vehicle_year')->nullable();
            $table->string('vehicle_color')->nullable();
            $table->string('registration_no')->nullable();
            $table->date('date_issued')->nullable();
            $table->date('expiry_date')->nullable();
            $table->json('document_images')->nullable();
            $table->string('plate_number')->unique()->nullable();
            $table->enum('type', ['Normal', 'Customized', 'Dealership'])->nullable();
            $table->string('preferred_name')->nullable();
            $table->enum('business_type', ['Co-operate', 'Business'])->nullable();
            $table->string('cac_document')->nullable();
            $table->string('letterhead')->nullable();
            $table->string('means_of_identification')->nullable();
            $table->enum('status', ['active', 'pending', 'rejected', 'unpaid'])->default('pending');
            $table->string('state_of_origin')->nullable();
            $table->string('local_government')->nullable();
            $table->string('blood_group')->nullable();
            $table->string('height')->nullable();
            $table->string('occupation')->nullable();
            $table->string('next_of_kin')->nullable();
            $table->string('next_of_kin_phone')->nullable();
            $table->string('mother_maiden_name')->nullable();
            $table->string('license_years')->nullable();
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
