<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('delivery_fees', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('state_id');
            $table->unsignedBigInteger('lga_id')->nullable();
            $table->decimal('fee', 10, 2);
            $table->timestamps();

            $table->foreign('state_id')->references('id')->on('states')->onDelete('cascade');
            $table->foreign('lga_id')->references('id')->on('lgas')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_fees');
    }
}; 