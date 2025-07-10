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
        Schema::create('payment_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_head_id')->constrained('payment_heads')->onUpdate('cascade')->onDelete('restrict');
            $table->foreignId('gateway_id')->constrained('gateways')->onUpdate('cascade')->onDelete('restrict');
            $table->foreignId('revenue_head_id')->constrained('revenue_heads')->onUpdate('cascade')->onDelete('restrict');
            $table->double('amount',15,2)->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_schedules');
    }
};
