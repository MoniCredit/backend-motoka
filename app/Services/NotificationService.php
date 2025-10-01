<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    /**
     * Create a notification for a user
     */
    public static function createNotification($userId, $type, $action, $message, $data = null)
    {
        try {
            $notification = Notification::create([
                'user_id' => $userId,
                'type' => $type,
                'action' => $action,
                'message' => $message,
                'is_read' => false,
            ]);

            // Add additional data if provided
            if ($data) {
                $notification->data = $data;
                $notification->save();
            }

            Log::info('Notification created', [
                'user_id' => $userId,
                'type' => $type,
                'action' => $action,
                'message' => $message
            ]);

            return $notification;
        } catch (\Exception $e) {
            Log::error('Failed to create notification', [
                'user_id' => $userId,
                'type' => $type,
                'action' => $action,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Create car-related notifications
     */
    public static function notifyCarOperation($userId, $action, $car, $additionalInfo = null)
    {
        // Get the user's userId string for the foreign key
        $user = \App\Models\User::find($userId);
        $userStringId = $user ? $user->userId : $userId;
        
        $messages = [
            'created' => "Your car {$car->vehicle_make} {$car->vehicle_model} ({$car->registration_no}) has been successfully registered.",
            'updated' => "Your car {$car->vehicle_make} {$car->vehicle_model} ({$car->registration_no}) information has been updated.",
            'deleted' => "Your car {$car->vehicle_make} {$car->vehicle_model} ({$car->registration_no}) has been removed from your account.",
            'renewed' => "Your car {$car->vehicle_make} {$car->vehicle_model} ({$car->registration_no}) registration has been renewed.",
            'expired' => "Your car {$car->vehicle_make} {$car->vehicle_model} ({$car->registration_no}) registration has expired.",
            'expiring_soon' => "Your car {$car->vehicle_make} {$car->vehicle_model} ({$car->registration_no}) registration expires soon.",
        ];

        $message = $messages[$action] ?? "Your car {$car->vehicle_make} {$car->vehicle_model} has been {$action}.";
        
        if ($additionalInfo) {
            $message .= " {$additionalInfo}";
        }

        return self::createNotification($userStringId, 'car', $action, $message, [
            'car_id' => $car->id,
            'car_slug' => $car->slug,
            'registration_no' => $car->registration_no,
            'vehicle_make' => $car->vehicle_make,
            'vehicle_model' => $car->vehicle_model,
        ]);
    }

    /**
     * Create driver license notifications
     */
    public static function notifyDriverLicenseOperation($userId, $action, $license, $additionalInfo = null)
    {
        $messages = [
            'created' => "Your driver license application has been submitted successfully.",
            'updated' => "Your driver license information has been updated.",
            'deleted' => "Your driver license has been removed from your account.",
            'approved' => "Congratulations! Your driver license has been approved and is ready for collection.",
            'rejected' => "Your driver license application has been rejected. Please review and resubmit.",
            'expired' => "Your driver license has expired. Please renew it.",
            'expiring_soon' => "Your driver license expires soon. Please renew it.",
        ];

        $message = $messages[$action] ?? "Your driver license has been {$action}.";
        
        if ($additionalInfo) {
            $message .= " {$additionalInfo}";
        }

        return self::createNotification($userId, 'driver_license', $action, $message, [
            'license_id' => $license->id,
            'license_slug' => $license->slug,
            'license_number' => $license->license_number ?? 'N/A',
        ]);
    }

    /**
     * Create plate number notifications
     */
    public static function notifyPlateNumberOperation($userId, $action, $plate, $additionalInfo = null)
    {
        $messages = [
            'created' => "Your plate number application has been submitted successfully.",
            'updated' => "Your plate number information has been updated.",
            'deleted' => "Your plate number has been removed from your account.",
            'approved' => "Congratulations! Your plate number has been approved and is ready for collection.",
            'rejected' => "Your plate number application has been rejected. Please review and resubmit.",
            'expired' => "Your plate number has expired. Please renew it.",
            'expiring_soon' => "Your plate number expires soon. Please renew it.",
        ];

        $message = $messages[$action] ?? "Your plate number has been {$action}.";
        
        if ($additionalInfo) {
            $message .= " {$additionalInfo}";
        }

        return self::createNotification($userId, 'plate_number', $action, $message, [
            'plate_id' => $plate->id,
            'plate_slug' => $plate->slug,
            'plate_number' => $plate->plate_number ?? 'N/A',
        ]);
    }

    /**
     * Create payment notifications
     */
    public static function notifyPaymentOperation($userId, $action, $payment, $additionalInfo = null)
    {
        // Get payment head name for more specific message
        $paymentHeadName = '';
        try {
            $paymentSchedule = \App\Models\PaymentSchedule::with('payment_head')->find($payment->payment_schedule_id);
            if ($paymentSchedule && $paymentSchedule->payment_head) {
                $paymentHeadName = $paymentSchedule->payment_head->payment_head_name;
            }
        } catch (\Exception $e) {
            // Fallback to payment description
            $paymentHeadName = $payment->payment_description ?: 'Payment';
        }

        $messages = [
            'created' => "Payment of ₦" . number_format($payment->amount, 2) . " for {$paymentHeadName} has been initiated.",
            'completed' => "Payment of ₦" . number_format($payment->amount, 2) . " for {$paymentHeadName} has been completed successfully.",
            'failed' => "Payment of ₦" . number_format($payment->amount, 2) . " for {$paymentHeadName} has failed. Please try again.",
            'refunded' => "Payment of ₦" . number_format($payment->amount, 2) . " for {$paymentHeadName} has been refunded to your account.",
            'renewed' => "Your car registration has been renewed successfully with payment of ₦" . number_format($payment->amount, 2) . " for {$paymentHeadName}.",
        ];

        $message = $messages[$action] ?? "Payment of ₦" . number_format($payment->amount, 2) . " for {$paymentHeadName} has been {$action}.";
        
        if ($additionalInfo) {
            $message .= " {$additionalInfo}";
        }

        return self::createNotification($userId, 'payment', $action, $message, [
            'payment_id' => $payment->id,
            'transaction_id' => $payment->transaction_id,
            'amount' => $payment->amount,
            'status' => $payment->status,
            'payment_type' => $paymentHeadName,
        ]);
    }

    /**
     * Create reminder notifications
     */
    public static function notifyReminder($userId, $type, $item, $reminderType, $daysLeft = null)
    {
        $messages = [
            'car_expiry' => "Reminder: Your car {$item->vehicle_make} {$item->vehicle_model} registration expires in {$daysLeft} days.",
            'license_expiry' => "Reminder: Your driver license expires in {$daysLeft} days.",
            'plate_expiry' => "Reminder: Your plate number expires in {$daysLeft} days.",
            'payment_due' => "Reminder: Payment is due for your {$type}.",
        ];

        $message = $messages[$reminderType] ?? "Reminder: {$type} requires attention.";
        
        $data = [
            'reminder_type' => $reminderType,
            'days_left' => $daysLeft,
        ];

        if ($type === 'car') {
            $data['car_id'] = $item->id;
            $data['car_slug'] = $item->slug;
        } elseif ($type === 'driver_license') {
            $data['license_id'] = $item->id;
            $data['license_slug'] = $item->slug;
        } elseif ($type === 'plate_number') {
            $data['plate_id'] = $item->id;
            $data['plate_slug'] = $item->slug;
        }

        return self::createNotification($userId, 'reminder', 'created', $message, $data);
    }

    /**
     * Create order notifications
     */
    public static function notifyOrderOperation($userId, $action, $order, $additionalInfo = null)
    {
        $messages = [
            'created' => "Your order for {$order->order_type} has been created successfully.",
            'updated' => "Your order for {$order->order_type} has been updated.",
            'cancelled' => "Your order for {$order->order_type} has been cancelled.",
            'completed' => "Your order for {$order->order_type} has been completed successfully.",
            'in_progress' => "Your order for {$order->order_type} is now in progress.",
            'declined' => "Your order for {$order->order_type} has been declined.",
        ];

        $message = $messages[$action] ?? "Your order for {$order->order_type} has been {$action}.";
        
        if ($additionalInfo) {
            $message .= " {$additionalInfo}";
        }

        return self::createNotification($userId, 'order', $action, $message, [
            'order_id' => $order->id,
            'order_slug' => $order->slug,
            'order_type' => $order->order_type,
            'amount' => $order->amount,
        ]);
    }

    /**
     * Get unread notification count for a user
     */
    public static function getUnreadCount($userId)
    {
        return Notification::where('user_id', $userId)
            ->where('is_read', false)
            ->count();
    }

    /**
     * Mark all notifications as read for a user
     */
    public static function markAllAsRead($userId)
    {
        return Notification::where('user_id', $userId)
            ->where('is_read', false)
            ->update(['is_read' => true]);
    }

    /**
     * Clean up old notifications (older than 30 days)
     */
    public static function cleanupOldNotifications($days = 30)
    {
        $cutoffDate = Carbon::now()->subDays($days);
        
        $deleted = Notification::where('created_at', '<', $cutoffDate)
            ->where('is_read', true)
            ->delete();

        Log::info("Cleaned up {$deleted} old notifications");
        return $deleted;
    }
}
