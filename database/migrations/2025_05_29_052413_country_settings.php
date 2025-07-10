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
        Schema::create('geo_political_zones', function (Blueprint $table) {
            $table->id();
            $table->string('geo_political_zone_name');
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
        });

        Schema::create('states', function (Blueprint $table) {
            $table->id();
            $table->string('state_name');
            $table->foreignId('geo_political_zone_id')->nullable()->constrained('geo_political_zones')->onUpdate('cascade')
                ->onDelete('restrict');
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
        });


        Schema::create('lgas', function (Blueprint $table) {
            $table->id();
            $table->string('lga_name');
            $table->foreignId('state_id')->nullable()->constrained('states')->onUpdate('cascade')
                ->onDelete('restrict');
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->string("code")->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('state_lgas');
        Schema::dropIfExists('geo_political_zones');
        Schema::dropIfExists('states');
    }
};
