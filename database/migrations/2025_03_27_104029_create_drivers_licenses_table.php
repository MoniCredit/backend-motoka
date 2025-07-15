<?php


use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        Schema::create('drivers_licenses', function (Blueprint $table) {
            $table->id();
            // $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('user_id', 6);
            $table->foreign('user_id')->references('userId')->on('users')->onDelete('cascade');
            $table->string('license_number')->unique()->nullable();
            $table->enum('license_type', ['new', 'renew']);
            $table->string('full_name')->nullable();
            $table->string('phone_number')->nullable();
            $table->text('address')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('place_of_birth')->nullable();
            $table->string('state_of_origin')->nullable();
            $table->string('local_government')->nullable();
            $table->string('blood_group')->nullable();
            $table->string('height')->nullable();
            $table->string('occupation')->nullable();
            $table->string('next_of_kin')->nullable();
            $table->string('next_of_kin_phone')->nullable();
            $table->string("mother_maiden_name")->nullable();
            $table->integer('license_year')->nullable(); // e.g., 5 years
            $table->string('passport_photo')->nullable();
            $table->enum('status', ['unpaid', 'active', 'pending', 'rejected'])->default('unpaid');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drivers_licenses');
    }
};

// return new class extends Migration {
//     public function up(): void {
//         Schema::create('drivers_licenses', function (Blueprint $table) {
//             $table->id();
//             $table->string('full_name');
//             $table->string('phone_no');
//             $table->text('address');
//             $table->date('date_of_birth');
//             $table->enum('license_type', ['new', 'renew']);
//             $table->string('passport_photo')->nullable();
//             $table->boolean('is_registered')->default(false);
//             $table->timestamps();
//         });
//     }

//     public function down(): void {
//         Schema::dropIfExists('drivers_licenses');
//     }
// };

