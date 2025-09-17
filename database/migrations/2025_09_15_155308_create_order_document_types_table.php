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
        Schema::create('order_document_types', function (Blueprint $table) {
            $table->id();
            $table->string('order_type'); // e.g., 'vehicle_license', 'drivers_license', etc.
            $table->string('document_name'); // e.g., 'Insurance', 'Vehicle License', etc.
            $table->string('document_key'); // e.g., 'insurance', 'vehicle_license', etc.
            $table->boolean('is_required')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_document_types');
    }
};
