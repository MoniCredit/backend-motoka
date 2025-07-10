<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('plates', function (Blueprint $table) {
            $table->id();
            // $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('user_id', 6);
            $table->foreign('user_id')->references('userId')->on('users')->onDelete('cascade');
            $table->string('plate_number')->unique();
            $table->enum('type', ['Normal', 'Customized', 'Dealership']);
            $table->string('preferred_name')->nullable();
            $table->string('full_name');
            $table->text('address');
            $table->string('chassis_number')->unique();
            $table->string('engine_number')->unique();
            $table->string('phone_number');
            $table->string('colour');
            $table->string('car_make');
            $table->string('car_type');
            $table->enum('business_type', ['Co-operate', 'Business'])->nullable();
            $table->string('cac_document')->nullable();
            $table->string('letterhead')->nullable();
            $table->string('means_of_identification')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('plates');
    }
};
