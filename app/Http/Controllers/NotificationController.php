<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        // Get the authenticated user
        $userId = Auth::user()->userId;

        // Get pagination parameters
        $perPage = $request->get('per_page', 20);
        $type = $request->get('type', 'all');
        $unreadOnly = $request->get('unread_only', false);

        // Build query
        $query = Notification::where('user_id', $userId);

        // Filter by type
        if ($type !== 'all') {
            $query->where('type', $type);
        }

        // Filter unread only
        if ($unreadOnly) {
            $query->where('is_read', false);
        }

        // Fetch notifications with pagination
        $notifications = $query->orderBy('created_at', 'desc')
            ->paginate($perPage);

        // Format notifications by date
        $groupedNotifications = [];
        foreach ($notifications->items() as $notification) {
            // Convert to local timezone
            $localCreatedAt = $notification->created_at->setTimezone(config('app.timezone'));
            $localUpdatedAt = $notification->updated_at->setTimezone(config('app.timezone'));

            // Update the notification object with local timestamps
            $notification->created_at = $localCreatedAt;
            $notification->updated_at = $localUpdatedAt;

            $date = $localCreatedAt->format('Y-m-d');
            if (!isset($groupedNotifications[$date])) {
                $groupedNotifications[$date] = [];
            }
            $groupedNotifications[$date][] = $notification;
        }

        return response()->json([
            'status' => 'success',
            'data' => $groupedNotifications,
            'pagination' => [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'per_page' => $notifications->perPage(),
                'total' => $notifications->total(),
            ],
            'unread_count' => NotificationService::getUnreadCount($userId),
        ]);
    }

    public function markAsRead($id)
    {
        $notification = Notification::find($id);
        if ($notification) {
            $notification->is_read = true;
            $notification->save();

            return response()->json([
                'status' => 'success',
                'message' => 'Notification marked as read.',
            ]);
        }

        return response()->json(['status' => 'error', 'message' => 'Notification not found.'], 404);
    }

    public function markAllAsRead()
    {
        $userId = Auth::user()->userId;
        $count = NotificationService::markAllAsRead($userId);

        return response()->json([
            'status' => 'success',
            'message' => "All notifications marked as read.",
            'count' => $count,
        ]);
    }

    public function getUnreadCount()
    {
        $userId = Auth::user()->userId;
        $count = NotificationService::getUnreadCount($userId);

        return response()->json([
            'status' => 'success',
            'unread_count' => $count,
        ]);
    }

    public function getByType($type)
    {
        $userId = Auth::user()->userId;
        
        $notifications = Notification::where('user_id', $userId)
            ->where('type', $type)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $notifications,
        ]);
    }

    public function delete($id)
    {
        $notification = Notification::find($id);
        
        if (!$notification) {
            return response()->json(['status' => 'error', 'message' => 'Notification not found.'], 404);
        }

        // Check if user owns the notification
        if ($notification->user_id !== Auth::user()->userId) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized.'], 403);
        }

        $notification->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Notification deleted successfully.',
        ]);
    }

    /**
     * Test endpoint to create a car renewal notification
     */
    public function testCarRenewalNotification()
    {
        $userId = Auth::user()->userId;
        
        // Get user's first car for testing
        $car = \App\Models\Car::where('user_id', $userId)->first();
        
        if (!$car) {
            return response()->json([
                'status' => 'error',
                'message' => 'No car found for testing'
            ], 404);
        }
        
        // Create a test car renewal notification
        $notification = \App\Services\NotificationService::notifyCarOperation(
            $userId, 
            'renewed', 
            $car, 
            "Test renewal payment of ₦500.00 completed successfully."
        );
        
        return response()->json([
            'status' => 'success',
            'message' => 'Test car renewal notification created',
            'notification' => $notification
        ]);
    }

    /**
     * Clear all notifications for testing
     */
    public function clearAllNotifications()
    {
        $userId = Auth::user()->userId;
        
        $deleted = \App\Models\Notification::where('user_id', $userId)->delete();
        
        return response()->json([
            'status' => 'success',
            'message' => "Cleared {$deleted} notifications",
            'deleted_count' => $deleted
        ]);
    }
}
