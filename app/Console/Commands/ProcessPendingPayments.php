<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Payment;
use App\Models\Car;
use App\Models\User;
use App\Http\Controllers\PaystackPaymentController;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use ReflectionClass;

class ProcessPendingPayments extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'payments:process-pending';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process pending Paystack payments and create orders';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Processing pending Paystack payments...');

        // Get all pending Paystack payments (only those created in the last 24 hours)
        $pendingPayments = Payment::where('payment_gateway', 'paystack')
            ->where('status', 'pending')
            ->whereNotNull('gateway_reference')
            ->where('created_at', '>=', now()->subHours(24))
            ->get();

        if ($pendingPayments->isEmpty()) {
            $this->info('No pending Paystack payments found.');
            return;
        }

        $this->info("Found {$pendingPayments->count()} pending payments to process.");

        $processed = 0;
        $failed = 0;

        foreach ($pendingPayments as $payment) {
            try {
                $this->info("Processing payment: {$payment->gateway_reference}");

                // Verify payment with Paystack
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . env('PAYSTACK_SECRET_KEY'),
                    'Content-Type' => 'application/json',
                ])->timeout(30)->get("https://api.paystack.co/transaction/verify/{$payment->gateway_reference}");

                $data = $response->json();

                if (!$response->successful() || !$data['status']) {
                    $this->warn("Payment verification failed for: {$payment->gateway_reference}");
                    $failed++;
                    continue;
                }

                // Check if payment is successful
                if ($data['data']['status'] === 'success') {
                    // Update payment status
                    $payment->update([
                        'status' => 'completed',
                        'gateway_response' => $data['data'],
                        'raw_response' => $data,
                    ]);

                    // Get car and user
                    $car = Car::find($payment->car_id);
                    $user = User::find($payment->user_id);

                    if ($car && $user) {
                        // Update car status
                        $car->status = 'active';
                        $car->save();

                        // Create orders
                        $controller = new PaystackPaymentController();
                        $reflection = new ReflectionClass($controller);
                        $method = $reflection->getMethod('createOrderFromPayment');
                        $method->setAccessible(true);
                        $method->invoke($controller, $payment, $car, $user);

                        $this->info("✅ Successfully processed payment: {$payment->gateway_reference}");
                        $processed++;
                        
                        // Log successful processing
                        Log::info('Payment processed successfully via scheduled task', [
                            'payment_id' => $payment->id,
                            'gateway_reference' => $payment->gateway_reference,
                            'amount' => $payment->amount,
                            'user_id' => $payment->user_id,
                            'car_id' => $payment->car_id
                        ]);
                    } else {
                        $this->error("❌ Car or User not found for payment: {$payment->gateway_reference}");
                        $failed++;
                        
                        Log::error('Payment processing failed - missing car or user', [
                            'payment_id' => $payment->id,
                            'gateway_reference' => $payment->gateway_reference,
                            'car_id' => $payment->car_id,
                            'user_id' => $payment->user_id
                        ]);
                    }
                } else {
                    $this->warn("Payment not successful for: {$payment->gateway_reference}");
                    $failed++;
                }

            } catch (\Exception $e) {
                $this->error("❌ Error processing payment {$payment->gateway_reference}: " . $e->getMessage());
                $failed++;
                Log::error('ProcessPendingPayments error', [
                    'payment_id' => $payment->id,
                    'gateway_reference' => $payment->gateway_reference,
                    'error' => $e->getMessage()
                ]);
            }
        }

        $this->info("Processing complete!");
        $this->info("✅ Successfully processed: {$processed} payments");
        $this->info("❌ Failed: {$failed} payments");

        return 0;
    }
}