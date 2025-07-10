<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        // Get the authenticated user
        $userId = Auth::user()->userId;

        // Fetch notifications for the user
        $notifications = Notification::where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->get();

        // Format notifications by date
        $groupedNotifications = [];
        foreach ($notifications as $notification) {
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
}
