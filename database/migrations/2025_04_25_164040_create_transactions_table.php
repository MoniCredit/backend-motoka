<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
{
    Schema::create('transactions', function (Blueprint $table) {
        $table->id();
        $table->string('user_id', 6);
        $table->foreign('user_id')->references('userId')->on('users')->onDelete('cascade');
        $table->string('transaction_id')->change();
        $table->string('transaction_description')->nullable();
        $table->double('amount',15,2)->default(0);
        $table->enum('status',['pending', 'success', 'failed'])->default('pending');
        $table->json('raw_response')->nullable();
        $table->timestamps();

        // If you want to enforce foreign key constraint:
        // $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
    });
}
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
