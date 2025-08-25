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
        // Step 1: Create new table with auto-increment id (without foreign key constraints)
        Schema::create('cars_new', function (Blueprint $table) {
            $table->id(); // Auto-incrementing integer id
            $table->uuid('slug')->unique();
            $table->string('user_id', 6);
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

        // Step 2: Copy data from old table to new table
        $cars = DB::table('cars')->get();
        foreach ($cars as $car) {
            DB::table('cars_new')->insert([
                'slug' => $car->slug,
                'user_id' => $car->user_id,
                'name_of_owner' => $car->name_of_owner,
                'phone_number' => $car->phone_number,
                'address' => $car->address,
                'vehicle_make' => $car->vehicle_make,
                'vehicle_model' => $car->vehicle_model,
                'registration_status' => $car->registration_status,
                'car_type' => $car->car_type,
                'chasis_no' => $car->chasis_no,
                'engine_no' => $car->engine_no,
                'vehicle_year' => $car->vehicle_year,
                'vehicle_color' => $car->vehicle_color,
                'registration_no' => $car->registration_no,
                'date_issued' => $car->date_issued,
                'expiry_date' => $car->expiry_date,
                'document_images' => $car->document_images,
                'plate_number' => $car->plate_number,
                'type' => $car->type,
                'preferred_name' => $car->preferred_name,
                'business_type' => $car->business_type,
                'cac_document' => $car->cac_document,
                'letterhead' => $car->letterhead,
                'means_of_identification' => $car->means_of_identification,
                'status' => $car->status,
                'state_of_origin' => $car->state_of_origin,
                'local_government' => $car->local_government,
                'blood_group' => $car->blood_group,
                'height' => $car->height,
                'occupation' => $car->occupation,
                'next_of_kin' => $car->next_of_kin,
                'next_of_kin_phone' => $car->next_of_kin_phone,
                'mother_maiden_name' => $car->mother_maiden_name,
                'license_years' => $car->license_years,
                'created_at' => $car->created_at,
                'updated_at' => $car->updated_at,
                'deleted_at' => $car->deleted_at,
            ]);
        }

        // Step 3: Drop old table and rename new table
        Schema::dropIfExists('cars');
        Schema::rename('cars_new', 'cars');

        // Step 4: Add foreign key constraint after table is created
        Schema::table('cars', function (Blueprint $table) {
            $table->foreign('user_id')->references('userId')->on('users')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // This is a complex migration, so we'll create a simple rollback
        // that adds back the UUID id and removes the slug
        Schema::table('cars', function (Blueprint $table) {
            $table->uuid('id')->primary()->change();
            $table->dropColumn('slug');
        });
    }
};
