<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Get all payments data
        $payments = DB::table('payments')->get();
        
        // Drop the existing table
        Schema::dropIfExists('payments');
        
        // Create new table with UUID primary key
        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('transaction_id');
            $table->decimal('amount', 10, 2);
            $table->unsignedBigInteger('payment_schedule_id')->nullable();
            $table->uuid('car_id')->nullable();
            $table->string('status')->default('pending');
            $table->string('reference_code')->nullable();
            $table->text('payment_description')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->json('raw_response')->nullable();
            $table->json('meta_data')->nullable();
            $table->timestamps();
        });

        // Reinsert data with new UUIDs
        foreach ($payments as $payment) {
            DB::table('payments')->insert([
                'id' => Str::uuid(),
                'transaction_id' => $payment->transaction_id,
                'amount' => $payment->amount,
                'payment_schedule_id' => $payment->payment_schedule_id,
                'car_id' => $payment->car_id,
                'status' => $payment->status,
                'reference_code' => $payment->reference_code,
                'payment_description' => $payment->payment_description,
                'user_id' => $payment->user_id,
                'raw_response' => $payment->raw_response,
                'meta_data' => $payment->meta_data,
                'created_at' => $payment->created_at,
                'updated_at' => $payment->updated_at,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Get all payments data
        $payments = DB::table('payments')->get();
        
        // Drop the existing table
        Schema::dropIfExists('payments');
        
        // Create new table with auto-increment primary key
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_id');
            $table->decimal('amount', 10, 2);
            $table->unsignedBigInteger('payment_schedule_id')->nullable();
            $table->uuid('car_id')->nullable();
            $table->string('status')->default('pending');
            $table->string('reference_code')->nullable();
            $table->text('payment_description')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->json('raw_response')->nullable();
            $table->json('meta_data')->nullable();
            $table->timestamps();
        });

        // Reinsert data with auto-increment IDs
        foreach ($payments as $payment) {
            DB::table('payments')->insert([
                'transaction_id' => $payment->transaction_id,
                'amount' => $payment->amount,
                'payment_schedule_id' => $payment->payment_schedule_id,
                'car_id' => $payment->car_id,
                'status' => $payment->status,
                'reference_code' => $payment->reference_code,
                'payment_description' => $payment->payment_description,
                'user_id' => $payment->user_id,
                'raw_response' => $payment->raw_response,
                'meta_data' => $payment->meta_data,
                'created_at' => $payment->created_at,
                'updated_at' => $payment->updated_at,
            ]);
        }
    }
};
