<?php

namespace App\Http\Controllers;

use App\Models\Car;
use App\Models\Lga;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Http;
use App\Models\Transaction;
use App\Models\Reminder;
use Carbon\Carbon;
use App\Models\Notification;
use App\Models\State;

class CarController extends Controller
{
    /**
     * Create a new controller instance.
     */
    // public function __construct()
    // {
    //     parent::__construct();
    //     $this->middleware('auth:api');
    // }

    /**
     * Register a new car
     */
    public function register(Request $request)
    {
        // Base validation rules for both registered and unregistered cars
        $baseRules = [
            'name_of_owner' => 'required|string|max:255',
            'phone_number' => 'nullable|string',
            'address' => 'required|string',
            'vehicle_make' => 'required|string|max:255',
            'vehicle_model' => 'required|string|max:255',
            'registration_status' => 'required|in:registered,unregistered',
            'car_type' => 'required|in:private,commercial',
            'chasis_no' => 'nullable|string',
            'engine_no' => 'nullable|string',
            'vehicle_year' => 'required|integer|digits:4|min:1900|max:' . (date('Y') + 1),
            'vehicle_color' => 'required|string|max:50'
        ];

        // Additional rules for registered cars
        $registeredRules = [
            'registration_no' => 'required|string',
            'date_issued' => 'required|date',
            'expiry_date' => 'required|date|after:date_issued',
            'document_images.*' => 'required |image|mimes:jpeg,png,jpg|max:2048',
        ];

        // Plate fields for unregistered cars
        $unregisteredPlateRules = [
            'plate_number' => 'nullable|string|unique:cars,plate_number',
            'type' => 'nullable|in:Normal,Customized,Dealership',
            'preferred_name' => 'nullable|string',
            'business_type' => 'nullable|in:Co-operate,Business',
            'cac_document' => 'nullable|file|mimes:pdf,jpg,png',
            'letterhead' => 'nullable|file|mimes:pdf,jpg,png',
            'means_of_identification' => 'nullable|file|mimes:pdf,jpg,png',
        ];
        // Apply validation rules based on registration status
        if ($request->registration_status === 'registered') {
            $rules = array_merge($baseRules, $registeredRules);
        } elseif ($request->registration_status === 'unregistered') {
            $rules = array_merge($baseRules, $unregisteredPlateRules);
        } else {
            $rules = $baseRules;
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }
        
        $userId= Auth::user()->userId;
        if (!$userId) {
            return response()->json(['message' => 'Unauthorized access.'], 403);
        }



        // Improved duplicate check for registration_no, chasis_no, engine_no
        $duplicateQuery = Car::where('user_id', $userId);
        $orConditions = [];
        if (!empty($request->registration_no)) {
            $orConditions[] = ['registration_no', '=', $request->registration_no];
        }
        if (!empty($request->chasis_no)) {
            $orConditions[] = ['chasis_no', '=', $request->chasis_no];
        }
        if (!empty($request->engine_no)) {
            $orConditions[] = ['engine_no', '=', $request->engine_no];
        }
        if (!empty($orConditions)) {
            $duplicateQuery->where(function($query) use ($orConditions) {
                foreach ($orConditions as $condition) {
                    $query->orWhere($condition[0], $condition[1], $condition[2]);
                }
            });
            if ($duplicateQuery->exists()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'A car with the same details already exists.',
                ]);
            }
        }
        
        // Handle document images upload to public/images/car-documents
        $documentImages = [];
        if ($request->hasFile('document_images')) {
            foreach ($request->file('document_images') as $image) {
                $filename = time() . '_' . uniqid() . '.' . $image->getClientOriginalExtension();
                $image->move(public_path('images/car-documents'), $filename);
                $documentImages[] = 'images/car-documents/' . $filename;
            }
        }

        // $user_id = Auth::user()->id;
        try {
            $carData = [
                'user_id' => $userId,
                'name_of_owner' => $request->name_of_owner,
                'phone_number' => $request->phone_number,
                'address' => $request->address,
                'vehicle_make' => $request->vehicle_make,
                'vehicle_model' => $request->vehicle_model,
                'registration_status' => $request->registration_status,
                'car_type' => $request->car_type,
                'chasis_no' => $request->chasis_no,
                'engine_no' => $request->engine_no,
                'vehicle_year' => $request->vehicle_year,
                'vehicle_color' => $request->vehicle_color,
                'status' => 'unpaid', // Always set to unpaid on registration
            ];

            // Add registered car specific fields
            if ($request->registration_status === 'registered') {
                $carData = array_merge($carData, [
                    'registration_no' => $request->registration_no,
                    'date_issued' => $request->date_issued,
                    'expiry_date' => $request->expiry_date,
                    'document_images' => $documentImages,
                ]);
            }

            // Add plate fields for unregistered cars
            if ($request->registration_status === 'unregistered') {
                $carData = array_merge($carData, [
                    'plate_number' => $request->plate_number,
                    'type' => $request->type,
                    'preferred_name' => $request->preferred_name,
                    'business_type' => $request->business_type,
                ]);
                // Handle file uploads for plate fields
                if ($request->hasFile('cac_document')) {
                    $filename = time() . '_' . uniqid() . '.' . $request->file('cac_document')->getClientOriginalExtension();
                    $request->file('cac_document')->move(public_path('images/car-documents'), $filename);
                    $carData['cac_document'] = 'images/car-documents/' . $filename;
                }
                if ($request->hasFile('letterhead')) {
                    $filename = time() . '_' . uniqid() . '.' . $request->file('letterhead')->getClientOriginalExtension();
                    $request->file('letterhead')->move(public_path('images/car-documents'), $filename);
                    $carData['letterhead'] = 'images/car-documents/' . $filename;
                }
                if ($request->hasFile('means_of_identification')) {
                    $filename = time() . '_' . uniqid() . '.' . $request->file('means_of_identification')->getClientOriginalExtension();
                    $request->file('means_of_identification')->move(public_path('images/car-documents'), $filename);
                    $carData['means_of_identification'] = 'images/car-documents/' . $filename;
                }
            }

            $car = Car::create($carData);

            // Do not set reminders here, as dates are not set yet
            // if ($request->registration_status === 'registered' && $request->expiry_date) {
            //     $this->handleReminder($userId, $request->expiry_date, 'car', $car->id);
            // } else {
            //     $this->deleteReminder($userId, 'car', $car->id);
            // }

           
            Notification::create([
                'user_id' => $userId,
                'type' => 'car',
                'action' => 'created',
                'message' => 'Your car has been registered successfully.',
            ]);

           
            $notifications = Notification::where('user_id', $userId)
                ->orderBy('created_at', 'desc')
                ->get();

          
            $groupedNotifications = [];
            foreach ($notifications as $notification) {
                $date = $notification->created_at->format('Y-m-d');
                if (!isset($groupedNotifications[$date])) {
                    $groupedNotifications[$date] = [];
                }
                $groupedNotifications[$date][] = $notification;
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Car registered successfully',
                'car' => $this->filterCarResponse($car),
            ]);
        } catch (\Exception $e) {
           
            foreach ($documentImages as $path) {
                Storage::disk('public')->delete($path);
            }

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to register car',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get user's cars
     */
    public function getMyCars()
    {
        $user_id = Auth::user()->userId;
        $cars = Car::where('user_id', $user_id)
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json([
            'status' => 'success',
            'cars' => $cars->map(fn($car) => $this->filterCarResponse($car)),
        ]);
    }

    /**
     * Get specific car details
     */
    public function show($id)
    {
        $car = Car::find($id);
        if (!$car) {
            return response()->json([
                'status' => 'error',
                'message' => 'Car not found'
            ], 404);
        }
        $userId = Auth::user()->userId;
        if ($car->user_id !== $userId) {
            return response()->json([
                'status' => 'error',
                'message' => 'You do not have permission to access this car.'
            ], 403);
        }
        return response()->json([
            'status' => 'success',
            'car' => $this->filterCarResponse($car)
        ]);
    }

    /**
     * Update car details
     */
    public function update(Request $request, $id)
    {
        $car = Car::find($id);
        if (!$car) {
            return response()->json([
                'status' => 'error',
                'message' => 'Car not found'
            ], 404);
        }
        $userId = Auth::user()->userId;
        if ($car->user_id !== $userId) {
            return response()->json([
                'status' => 'error',
                'message' => 'You do not have permission to update this car.'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'name_of_owner' => 'nullable|string',
            'phone_number' => 'nullable|string',
            'address' => 'nullable|string',
            'vehicle_make' => 'nullable|string',
            'vehicle_model' => 'nullable|string',
            'registration_status' => 'nullable|in:registered,unregistered',
            'chasis_no' => 'nullable|string',
            'engine_no' => 'nullable|string',
            'vehicle_year' => 'nullable|integer|digits:4|min:1900|max:' . (date('Y') + 1),
            'vehicle_color' => 'nullable|string',
            'registration_no' => 'nullable|string',
            'date_issued' => 'nullable|date',
            'expiry_date' => 'nullable|date|after:date_issued',
            'document_images.*' => 'nullable |image|mimes:jpeg,png,jpg|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Handle new document images
        if ($request->hasFile('document_images')) {
            $documentImages = $car->document_images ?? [];

            foreach ($request->file('document_images') as $image) {
                $path = $image->store('car-documents', 'public');
                $documentImages[] = $path;
            }

            $car->document_images = $documentImages;
        }

        // Allow updating date_issued and expiry_date
        $requestData = $request->except(['status']);
        $car->update($requestData);

        if ($car->registration_status === 'registered' && $car->expiry_date) {
            $this->handleReminder($userId, $car->expiry_date, 'car', $car->id);
        } else {
            $this->deleteReminder($userId, 'car', $car->id);
        }

        Notification::create([
            'user_id' => $userId,
            'type' => 'car',
            'action' => 'updated',
            'message' => 'Your car details have been updated successfully.',
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Car updated successfully',
            'car' => $car
        ]);
    }


    /**
     * Delete a car
     */
   

    public function destroy($id)
    {
        $userId = Auth::user()->userId;
        $car = Car::find($id);
        if (!$car) {
            return response()->json([
                'status' => 'error',
                'message' => 'Car not found.'
            ], 404);
        }
        if ($car->user_id !== $userId) {
            return response()->json([
                'status' => 'error',
                'message' => 'You do not have permission to delete this car.'
            ], 403);
        }
        // Delete associated documents
        if (!empty($car->document_images)) {
            foreach ($car->document_images as $path) {
                Storage::disk('public')->delete($path);
            }
        }
        $car->delete();
        // Delete associated reminders
        Reminder::where('user_id', $userId)
            ->where('ref_id', $id) // Assuming ref_id is the car ID
            ->delete();
        // Optional: record a notification
        Notification::create([
            'user_id' => $userId,
            'type' => 'car',
            'action' => 'deleted',
            'message' => 'Your car has been deleted successfully.',
        ]);
        return response()->json([
            'status' => 'success',
            'message' => 'Car and associated reminders deleted successfully.'
        ]);
    }


    public function InsertDetail(Request $request)
    {

        $url = "https://api.paystack.co/transaction/initialize";
        $fields = [
            'email' => $request->email,
            'amount' => $request->amount
            ];
            $fields_string = http_build_query($fields);

            $ch = curl_init();
            $SECRET_KEY = 'sk_test_ed10add7e4f28be7fc2620d55909e970d4835dbb';
            
            curl_setopt($ch,CURLOPT_URL, $url);
            curl_setopt($ch,CURLOPT_POST, true);
            curl_setopt($ch,CURLOPT_POSTFIELDS, $fields_string);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "Authorization: Bearer " . $SECRET_KEY,
            "Cache-Control: no-cache",
            ));
            
            curl_setopt($ch,CURLOPT_RETURNTRANSFER, true); 
            
            $result = curl_exec($ch);
            echo $result;
            
            
            Transaction::create([
                'user_id' => Auth::user()->userId,
                'transaction_id' => json_decode($result)->data->reference,
                'amount' => $request->amount,
                'transaction_description' => $request->transaction_description ?? null,
                'status' => "pending"
            ]);

            curl_close($ch);
    }
    public function Verification(Request $request)
    {
        $transaction_id = $request->transaction_id; 
    
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . env('PAYSTACK_SECRET_KEY'),
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-cache',
        ])->get("https://api.paystack.co/transaction/verify/{$transaction_id}");
    
        $result = $response->json();
        
        
         $user_id = Auth::user()->userId;
    
        if (isset($result['data']['status'])) {
            $status = $result['data']['status'];
    
            if ($status == 'success') {
                Transaction::where('user_id', $user_id)
                    ->where('transaction_id', $transaction_id)
                    ->update(['status' => 'success']);
            } elseif ($result['status'] == false) {
                Transaction::where('user_id', $user_id)
                    ->where('transaction_id', $transaction_id)
                    ->update(['status' => 'pending']);
            } else {
                Transaction::where('user_id', $user_id)
                    ->where('transaction_id', $transaction_id)
                    ->update(['status' => 'failed']);
            }
    
            // Always update raw response
            Transaction::where('user_id', $user_id)
                ->where('transaction_id', $transaction_id)
                ->update([
                    'raw_response' => json_encode($result)
                ]);
    
            // If successful and transaction record updated
            $success = Transaction::where('user_id', $user_id)
                ->where('transaction_id', $transaction_id)
                ->first();
    
            
             return response()->json(['message' => 'Verified','data'=> $success], 200);
        }
        return response()->json(['message' => 'Unable to verify transaction'], 400);
    }




   // Updated handleReminder function for CarController.php
private function handleReminder($userId, $expiryDate, $type, $refId)
{
    // Parse the expiry date and get current date
    $reminderDate = Carbon::parse($expiryDate)->startOfDay();
    $now = Carbon::now()->startOfDay();
    $daysLeft = $now->diffInDays($reminderDate, false);

    // Log for debugging
    // \Log::info("Processing reminder for user: {$userId}, car: {$refId}, expiry: {$expiryDate}, days left: {$daysLeft}");

    // Handle expired (negative days)
    if ($daysLeft < 0) {
        $message = 'License Expired.';
        
        Reminder::updateOrCreate(
            [
                'user_id' => $userId,
                'type' => $type,
                'ref_id' => $refId,
            ],
            [
                'message' => $message,
                'remind_at' => $now->format('Y-m-d H:i:s'),
                'is_sent' => false
            ]
        );
        
        // \Log::info("Created EXPIRED reminder for car {$refId}: {$message}");
        return;
    }

    // Handle expiring today
    if ($daysLeft === 0) {
        $message = 'Your car registration expires today! Please renew now.';
        
        Reminder::updateOrCreate(
            [
                'user_id' => $userId,
                'type' => $type,
                'ref_id' => $refId,
            ],
            [
                'message' => $message,
                'remind_at' => $now->format('Y-m-d H:i:s'),
                'is_sent' => false
            ]
        );
        
        // \Log::info("Created TODAY reminder for car {$refId}: {$message}");
        return;
    }

    // If more than 30 days, delete existing reminders
    if ($daysLeft > 30) {
        Reminder::where('user_id', $userId)
            ->where('type', $type)
            ->where('ref_id', $refId)
            ->delete();
        
        // \Log::info("Deleted reminder for car {$refId} - more than 30 days left");
        return;
    }

    // Between 1 and 30 days: send countdown reminder
    $message = "Expires in {$daysLeft} day" . ($daysLeft > 1 ? 's' : '') . ".";

    Reminder::updateOrCreate(
        [
            'user_id' => $userId,
            'type' => $type,
            'ref_id' => $refId,
        ],
        [
            'message' => $message,
            'remind_at' => $now->format('Y-m-d H:i:s'),
            'is_sent' => false
        ]
    );
    
    // Log::info("Created COUNTDOWN reminder for car {$refId}: {$message}");
}

private function deleteReminder($userId, $type, $refId)
{
    $deleted = Reminder::where('user_id', $userId)
        ->where('type', $type)
        ->where('ref_id', $refId)
        ->delete();
    
    // \Log::info("Deleted {$deleted} reminders for user {$userId}, type {$type}, ref_id {$refId}");
}

public function getAllState(Request $request) {
    $states = State::all();
    return response()->json([
        'status' => true,
        'data' => $states
    ], 200);
}
public function getLgaByState($state_id)
{
    $lgas = \App\Models\Lga::where('state_id', $state_id)
        ->with('deliveryFee')
        ->get();

    return response()->json([
        'status' => true ,
        'data' =>  $lgas
    ], 200);
}

    // Add a new method to add plate info to an existing unregistered car
    public function addPlateToUnregisteredCar(Request $request, $car_id)
    {
        $userId = Auth::user()->userId;
        $car = Car::where('id', $car_id)->where('user_id', $userId)->where('registration_status', 'unregistered')->first();
        if (!$car) {
            return response()->json(['status' => 'error', 'message' => 'Unregistered car not found'], 404);
        }
        $rules = [
            'plate_number' => 'required|string|unique:cars,plate_number,' . $car->id,
            'type' => 'required|in:Normal,Customized,Dealership',
            'preferred_name' => 'nullable|string',
            'business_type' => 'nullable|in:Co-operate,Business',
            'cac_document' => 'nullable|file|mimes:pdf,jpg,png',
            'letterhead' => 'nullable|file|mimes:pdf,jpg,png',
            'means_of_identification' => 'nullable|file|mimes:pdf,jpg,png',
        ];
        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }
        $updateData = [
            'plate_number' => $request->plate_number,
            'type' => $request->type,
            'preferred_name' => $request->preferred_name,
            'business_type' => $request->business_type,
        ];
        if ($request->hasFile('cac_document')) {
            $updateData['cac_document'] = $request->file('cac_document')->store('car-documents', 'public');
        }
        if ($request->hasFile('letterhead')) {
            $updateData['letterhead'] = $request->file('letterhead')->store('car-documents', 'public');
        }
        if ($request->hasFile('means_of_identification')) {
            $updateData['means_of_identification'] = $request->file('means_of_identification')->store('car-documents', 'public');
        }
        $car->update($updateData);
        return response()->json(['status' => 'success', 'message' => 'Plate info added to car', 'car' => $car]);
    }

    private function filterCarResponse($car) {
        $data = $car->toArray();
        $plateFields = [
            'plate_number', 'type', 'preferred_name', 'business_type', 'cac_document', 'letterhead',
            'means_of_identification', 'state_of_origin', 'local_government', 'blood_group', 'height',
            'occupation', 'next_of_kin', 'next_of_kin_phone', 'mother_maiden_name', 'license_years'
        ];
        if ($car->registration_status === 'registered') {
            foreach ($plateFields as $field) {
                unset($data[$field]);
            }
        }
        return $data;
    }
}