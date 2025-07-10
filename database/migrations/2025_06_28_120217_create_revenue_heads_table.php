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
        Schema::create('revenue_heads', function (Blueprint $table) {
            $table->id();
            $table->string('revenue_head_name');
            $table->string('revenue_head_code');
            $table->foreignId('bank_id')
                ->constrained('banks')
                ->onUpdate('cascade')
                ->onDelete('restrict');
            $table->foreignId('gateway_id')
                ->constrained('gateways')
                ->onUpdate('cascade')
                ->onDelete('restrict');
            $table->enum('fee_bearer', ['merchant', 'customer'])->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('revenue_heads');
    }
};
