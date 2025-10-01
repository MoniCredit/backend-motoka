<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\PaymentSchedule;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Log;

class PaymentService
{
    /**
     * Handle payment completion and create appropriate notifications
     */
    public static function handlePaymentCompletion($payment)
    {
        try {
            // Check if notification already exists for this payment
            $existingNotification = \App\Models\Notification::where('user_id', $payment->user->userId ?? null)
                ->where('type', 'payment')
                ->where('action', 'completed')
                ->whereJsonContains('data->payment_id', $payment->id)
                ->first();
                
            if ($existingNotification) {
                Log::info('Payment completion notification already exists', [
                    'payment_id' => $payment->id,
                    'notification_id' => $existingNotification->id
                ]);
                return;
            }

            // Get user
            $user = \App\Models\User::find($payment->user_id);
            if (!$user) {
                Log::error('Payment completion: User not found', ['payment_id' => $payment->id]);
                return;
            }

            // Get car
            $car = \App\Models\Car::find($payment->car_id);
            
            // Create appropriate notification based on payment type
            self::createPaymentNotification($user->userId, $payment, $car);
            
            Log::info('Payment completion notification created', [
                'payment_id' => $payment->id,
                'user_id' => $user->id,
                'car_id' => $car ? $car->id : null
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error handling payment completion', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Create appropriate notification based on payment type
     */
    private static function createPaymentNotification($userId, $payment, $car)
    {
        $isRenewal = false;
        $paymentHeadName = '';
        
        // Get payment schedule to determine the type of payment
        try {
            // Load the payment schedule with its payment head
            $paymentSchedule = PaymentSchedule::with('payment_head')->find($payment->payment_schedule_id);
            
            if ($paymentSchedule && $paymentSchedule->payment_head) {
                $paymentHeadName = strtolower($paymentSchedule->payment_head->payment_head_name);
                
                // Check if this is a car renewal payment
                if (strpos($paymentHeadName, 'license') !== false || 
                    strpos($paymentHeadName, 'renewal') !== false ||
                    strpos($paymentHeadName, 'vehicle license') !== false) {
                    $isRenewal = true;
                }
            }
            
            // Also check payment description for renewal keywords
            if (!$isRenewal && $payment->payment_description) {
                $description = strtolower($payment->payment_description);
                if (strpos($description, 'license') !== false || 
                    strpos($description, 'renewal') !== false ||
                    strpos($description, 'vehicle license') !== false) {
                    $isRenewal = true;
                }
            }
            
        } catch (\Exception $e) {
            // Log error but continue with generic notification
            Log::error('Error determining payment type for notification', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage()
            ]);
        }
        
        if ($isRenewal && $car) {
            // Get payment head name for more specific message
            $paymentHeadName = '';
            try {
                $paymentSchedule = PaymentSchedule::with('payment_head')->find($payment->payment_schedule_id);
                if ($paymentSchedule && $paymentSchedule->payment_head) {
                    $paymentHeadName = $paymentSchedule->payment_head->payment_head_name;
                }
            } catch (\Exception $e) {
                // Fallback to payment description
                $paymentHeadName = $payment->payment_description ?: 'Vehicle License';
            }
            
            // Create car renewal notification with specific payment type
            $message = "Payment of ₦" . number_format($payment->amount, 2) . " for {$paymentHeadName} completed successfully.";
            NotificationService::notifyCarOperation($userId, 'renewed', $car, $message);
        } else {
            // Create generic payment notification
            NotificationService::notifyPaymentOperation($userId, 'completed', $payment);
        }
    }
}
