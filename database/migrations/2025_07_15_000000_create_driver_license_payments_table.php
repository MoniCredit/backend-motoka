<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('driver_license_payments', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['new', 'renew', 'lost_damaged']);
            $table->string('name');
            $table->decimal('amount', 10, 2);
            $table->string('revenue_head_code')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_license_payments');
    }
}; 