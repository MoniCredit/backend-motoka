<?php

namespace App\Http\Controllers;

use App\Models\Car;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class PlateController extends Controller
{
    public function index(Request $request)
    {
        $userId = Auth::user()->userId;
        $cars = Car::where('user_id', $userId)
            ->where('registration_status', 'unregistered')
            ->whereNotNull('plate_number')
            ->get();
        return response()->json($cars);
    }

    public function store(Request $request)
    {
        $userId = Auth::user()->userId;
        
        // Base validation rules
        $baseRules = [
            'type' => 'required|in:Normal,Customized,Dealership',
            'preferred_name' => 'nullable|string|max:255',
            'cac_document' => 'nullable|file|mimes:pdf,jpg,png,jpeg|max:10240',
            'letterhead' => 'nullable|file|mimes:pdf,jpg,png,jpeg|max:10240',
            'means_of_identification' => 'nullable|file|mimes:pdf,jpg,png,jpeg|max:10240',
        ];

        // Additional rules for dealership type (only files required for add-plate, full details for new application)
        $dealershipRules = [
            'business_type' => 'required|in:Co-operate,Business',
            'cac_document' => 'required|file|mimes:pdf,jpg,png,jpeg|max:10240',
            'letterhead' => 'required|file|mimes:pdf,jpg,png,jpeg|max:10240',
            'means_of_identification' => 'required|file|mimes:pdf,jpg,png,jpeg|max:10240',
        ];

        // Additional rules for customized type
        $customizedRules = [
            'preferred_name' => 'required|string|max:255',
            'means_of_identification' => 'required|file|mimes:pdf,jpg,png,jpeg|max:10240',
        ];

        // Initialize rules based on type
        if ($request->type === 'Dealership') {
            $rules = array_merge($baseRules, $dealershipRules);
        } elseif ($request->type === 'Customized') {
            $rules = array_merge($baseRules, $customizedRules);
        } else {
            $rules = $baseRules;
        }

        // Add car-specific rules if car_id is provided
        if ($request->car_id) {
            $rules['car_id'] = 'required|exists:cars,id';
        } else {
            // Rules for creating new car - need full details for dealership
            $rules = array_merge($rules, [
                'full_name' => 'required|string|max:255',
                'address' => 'required|string',
                'chasis_no' => 'required|string|unique:cars,chasis_no',
                'engine_no' => 'required|string|unique:cars,engine_no',
                'phone_number' => 'required|string',
                'vehicle_make' => 'required|string|max:255',
                'vehicle_model' => 'required|string|max:255',
                'vehicle_color' => 'required|string|max:50',
                'car_type' => 'required|in:private,commercial',
                'vehicle_year' => 'required|integer|digits:4|min:1900|max:' . (date('Y') + 1),
            ]);
            
            // For new car, dealership needs company details
            if ($request->type === 'Dealership') {
                $rules = array_merge($rules, [
                    'company_name' => 'required|string|max:255',
                    'company_address' => 'required|string',
                    'company_phone' => 'required|string',
                    'cac_number' => 'required|string|max:255',
                ]);
            }
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            if ($request->car_id) {
                // Attach plate info to existing unregistered car
                $car = Car::where('id', $request->car_id)
                    ->where('user_id', $userId)
                    ->where('registration_status', 'unregistered')
                    ->first();

                if (!$car) {
                    return response()->json([
                        'status' => 'error', 
                        'message' => 'Unregistered car not found'
                    ], 404);
                }

                $updateData = [
                    'type' => $request->type,
                    'preferred_name' => $request->preferred_name,
                ];

                // Handle dealership specific fields
                if ($request->type === 'Dealership') {
                    $updateData = array_merge($updateData, [
                        'business_type' => $request->business_type,
                    ]);
                }

                // Handle file uploads
                $this->handleFileUploads($request, $updateData);

                $car->update($updateData);

                // Create notification for plate number update
                NotificationService::notifyPlateNumberOperation($userId, 'updated', $car);

                return response()->json([
                    'status' => 'success', 
                    'message' => 'Plate information updated successfully',
                    'car' => $car
                ], 200);

            } else {
                // Create new unregistered car with plate info
                $carData = [
                    'user_id' => $userId,
                    'type' => $request->type,
                    'preferred_name' => $request->preferred_name,
                    'name_of_owner' => $request->full_name,
                    'address' => $request->address,
                    'chasis_no' => $request->chasis_no,
                    'engine_no' => $request->engine_no,
                    'phone_number' => $request->phone_number,
                    'vehicle_make' => $request->vehicle_make,
                    'vehicle_model' => $request->vehicle_model,
                    'vehicle_color' => $request->vehicle_color,
                    'car_type' => $request->car_type,
                    'vehicle_year' => $request->vehicle_year,
                    'registration_status' => 'unregistered',
                    'status' => 'unpaid',
                ];

                // Handle dealership specific fields
                if ($request->type === 'Dealership') {
                    $carData = array_merge($carData, [
                        'business_type' => $request->business_type,
                        'company_name' => $request->company_name,
                        'company_address' => $request->company_address,
                        'company_phone' => $request->company_phone,
                        'cac_number' => $request->cac_number,
                    ]);
                }

                // Handle file uploads
                $this->handleFileUploads($request, $carData);

                $car = Car::create($carData);

                // Create notification for plate number creation
                NotificationService::notifyPlateNumberOperation($userId, 'created', $car);

                return response()->json([
                    'status' => 'success',
                    'message' => 'Plate application submitted successfully',
                    'car' => $car
                ], 201);
            }

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to process plate application',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Handle file uploads for plate applications
     */
    private function handleFileUploads(Request $request, array &$data)
    {
        $uploadFields = ['cac_document', 'letterhead', 'means_of_identification'];
        
        foreach ($uploadFields as $field) {
            if ($request->hasFile($field)) {
                $file = $request->file($field);
                $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                $file->move(public_path('images/car-documents'), $filename);
                $data[$field] = 'images/car-documents/' . $filename;
            }
        }
    }

    /**
     * Get plate application details
     */
    public function show($slug)
    {
        $userId = Auth::user()->userId;
        $car = Car::where('slug', $slug)
            ->where('user_id', $userId)
            ->where('registration_status', 'unregistered')
            ->first();

        if (!$car) {
            return response()->json([
                'status' => 'error',
                'message' => 'Plate application not found'
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'car' => $car
        ]);
    }

    /**
     * Get available plate types and their requirements
     */
    public function getPlateTypes()
    {
        $plateTypes = [
            'Normal' => [
                'name' => 'Normal',
                'description' => 'Standard plate number',
                'requirements' => [
                    'Vehicle details',
                    'Owner information'
                ]
            ],
            'Customized' => [
                'name' => 'Customized',
                'description' => 'Personalized plate number',
                'requirements' => [
                    'Vehicle details',
                    'Owner information',
                    'Preferred name',
                    'Means of identification'
                ]
            ],
            'Dealership' => [
                'name' => 'Dealership',
                'description' => 'Business/Corporate plate number',
                'sub_types' => [
                    'Co-operate' => [
                        'name' => 'Co-operate',
                        'description' => 'Corporate organization plate',
                        'requirements' => [
                            'Company details',
                            'CAC registration',
                            'Letterhead',
                            'Means of identification'
                        ]
                    ],
                    'Business' => [
                        'name' => 'Business',
                        'description' => 'Business organization plate',
                        'requirements' => [
                            'Company details',
                            'CAC registration',
                            'Letterhead',
                            'Means of identification'
                        ]
                    ]
                ]
            ]
        ];

        return response()->json([
            'status' => 'success',
            'plate_types' => $plateTypes
        ]);
    }
}
