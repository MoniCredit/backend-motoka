<?php

namespace App\Observers;

use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Support\Facades\Log;

class PaymentObserver
{
    /**
     * Handle the Payment "updated" event.
     */
    public function updated(Payment $payment)
    {
        // Check if payment status changed to completed
        if ($payment->isDirty('status') && $payment->status === 'completed') {
            // Only create notification if it wasn't already completed
            if ($payment->getOriginal('status') !== 'completed') {
                Log::info('Payment status changed to completed, creating notification', [
                    'payment_id' => $payment->id,
                    'user_id' => $payment->user_id,
                    'amount' => $payment->amount
                ]);
                
                PaymentService::handlePaymentCompletion($payment);
            }
        }
    }

    /**
     * Handle the Payment "created" event.
     */
    public function created(Payment $payment)
    {
        // Only create notification if payment is immediately completed
        if ($payment->status === 'completed') {
            Log::info('Payment created as completed, creating notification', [
                'payment_id' => $payment->id,
                'user_id' => $payment->user_id,
                'amount' => $payment->amount
            ]);
            
            PaymentService::handlePaymentCompletion($payment);
        }
    }
}