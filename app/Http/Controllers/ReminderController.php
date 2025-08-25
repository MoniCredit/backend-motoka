<?php

namespace App\Http\Controllers;

use App\Models\Reminder;
use App\Models\Car;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ReminderController extends Controller
{
    // Fetch all reminders for the authenticated user
    public function index(Request $request)
    {
        // Get the authenticated user
        $userId = Auth::user()->userId;

        // Fetch all reminders for the user with car details
        $reminders = Reminder::with('car')
            ->where('user_id', $userId)
            ->where('is_sent', false)
            ->orderBy('created_at', 'desc')
            ->get();

       
        Log::info('Fetched Reminders for User ID ' . $userId . ':', $reminders->toArray());

        if ($reminders->isEmpty()) {
            return response()->json([
                'status' => 'success',
                'message' => 'No reminders found.',
                'data' => []
            ]);
        }

        // Prepare the response with car details for each reminder
        $response = [];
        foreach ($reminders as $reminder) {
            $carId = $reminder->ref_id;
            // Get car details for this reminder
            $car = Car::where('id', $carId)
                ->where('user_id', $userId)
                ->first();

            if ($car) {
                $daysLeft = \Carbon\Carbon::now()->diffInDays(\Carbon\Carbon::parse($car->expiry_date), false);
                if ($daysLeft <= 30) { // Only show reminders for cars expiring in 30 days or less
                    $response[] = [
                        'reminder_id' => $reminder->id,
                        'car_slug' => $car->slug,
                        'car_details' => [
                            'name_of_owner' => $car->name_of_owner,
                            'vehicle_make' => $car->vehicle_make,
                            'vehicle_model' => $car->vehicle_model,
                            'registration_no' => $car->registration_no,
                            'expiry_date' => $car->expiry_date,
                            'vehicle_color' => $car->vehicle_color,
                        ],
                        'reminder' => [
                            'id' => $reminder->id,
                            'user_id' => $reminder->user_id,
                            'type' => $reminder->type,
                            'message' => $reminder->message,
                            'remind_at' => $reminder->remind_at,
                            'is_sent' => $reminder->is_sent,
                            'created_at' => $reminder->created_at,
                            'updated_at' => $reminder->updated_at,
                        ],
                    ];
                }
            }
        }

        return response()->json([
            'status' => 'success',
            'data' => $response,
        ]);
    }

    // Update an existing reminder
    public function store(Request $request)
    {
        $request->validate([
            'type' => 'required|string',
            'message' => 'required|string',
            'remind_at' => 'required|date',
            'ref_id' => 'required|string',
        ]);

        $userId = Auth::user()->userId;

        // Find the existing reminder
        $reminder = Reminder::where('user_id', $userId)
            ->where('ref_id', $request->ref_id)
            ->where('type', $request->type)
            ->first();

        if ($reminder) {
            // Update the existing reminder
            $reminder->update([
                'message' => $request->message,
                'remind_at' => $request->remind_at,
                'is_sent' => false, 
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Reminder updated successfully',
                'data' => $reminder,
            ]);
        }

        // If no reminder exists, create a new one
        $reminder = Reminder::create([
            'user_id' => $userId,
            'type' => $request->type,
            'message' => $request->message,
            'remind_at' => $request->remind_at,
            'ref_id' => $request->ref_id,
            'is_sent' => false,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Reminder created successfully',
            'data' => $reminder,
        ], 201);
    }

    // Mark reminder as sent
    public function markAsSent($id)
    {
        $userId = Auth::user()->userId;
        
        $reminder = Reminder::where('id', $id)
            ->where('user_id', $userId)
            ->first();

        if (!$reminder) {
            return response()->json([
                'status' => 'error',
                'message' => 'Reminder not found'
            ], 404);
        }

        $reminder->update(['is_sent' => true]);

        return response()->json([
            'status' => 'success',
            'message' => 'Reminder marked as sent'
        ]);
    }

    // Delete a reminder
    public function destroy($id)
    {
        $userId = Auth::user()->userId;
        
        $reminder = Reminder::where('id', $id)
            ->where('user_id', $userId)
            ->first();

        if (!$reminder) {
            return response()->json([
                'status' => 'error',
                'message' => 'Reminder not found'
            ], 404);
        }

        $reminder->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Reminder deleted successfully'
        ]);
    }
}