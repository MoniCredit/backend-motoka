<?php

namespace App\Http\Controllers;

use App\Models\DriverLicense;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use App\Models\Notification;
use App\Models\DriverLicensePayment;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use App\Models\DriverLicenseTransaction;

class DriverLicenseController extends Controller
{
    // Create a new driver license (status: unpaid)
    public function store(Request $request)
    {
        $baseRules = [
            'license_type' => 'required|in:new,renew,lost_damaged',
        ];
        $type = $request->license_type;
        if ($type === 'new') {
            $rules = array_merge($baseRules, [
                'full_name' => 'required|string',
                'phone_number' => 'required|string',
                'address' => 'required|string',
                'date_of_birth' => 'required|date',
                'place_of_birth' => 'required|string',
                'state_of_origin' => 'required|string',
                'local_government' => 'required|string',
                'blood_group' => 'required|string',
                'height' => 'required|string',
                'occupation' => 'required|string',
                'next_of_kin' => 'required|string',
                'next_of_kin_phone' => 'required|string',
                'mother_maiden_name' => 'required|string',
                'license_year' => 'required|integer|in:3,5',
                'passport_photograph' => 'required|image|mimes:jpeg,png,jpg|max:10240',
            ], [
                'license_year.in' => 'License year must be either 3 years or 5 years only.',
            ]);
        } elseif ($type === 'renew') {
            $rules = array_merge($baseRules, [
                'expired_license_upload' => 'required|file|mimes:jpeg,png,jpg,pdf',
            ]);
        } elseif ($type === 'lost_damaged') {
            $rules = array_merge($baseRules, [
                'license_number' => 'required|string',
                'date_of_birth' => 'required|date',
            ]);
        } else {
            $rules = $baseRules;
        }
        $validated = $request->validate($rules);
        $userId = Auth::user()->userId;
        $data = [
            'license_type' => $type,
            'user_id' => $userId,
            'status' => 'unpaid',
        ];
        if ($type === 'new') {
            $data = array_merge($data, $request->only([
                'full_name', 'phone_number', 'address', 'date_of_birth', 'place_of_birth', 'state_of_origin',
                'local_government', 'blood_group', 'height', 'occupation', 'next_of_kin', 'next_of_kin_phone',
                'mother_maiden_name', 'license_year',
            ]));
            // Save passport photograph before duplicate check
            if ($request->hasFile('passport_photograph')) {
                $filename = time() . '_' . uniqid() . '.' . $request->file('passport_photograph')->getClientOriginalExtension();
                $request->file('passport_photograph')->move(public_path('images/driver-passports'), $filename);
                $data['passport_photograph'] = 'images/driver-passports/' . $filename;
            }
        } elseif ($type === 'renew') {
            // Save expired license upload before duplicate check
            if ($request->hasFile('expired_license_upload')) {
                $filename = time() . '_' . uniqid() . '.' . $request->file('expired_license_upload')->getClientOriginalExtension();
                $request->file('expired_license_upload')->move(public_path('images/expired-licenses'), $filename);
                $data['expired_license_upload'] = 'images/expired-licenses/' . $filename;
            }
        } elseif ($type === 'lost_damaged') {
            $data['license_number'] = $request->license_number;
            $data['date_of_birth'] = $request->date_of_birth;
        }
        // Prevent duplicate license for same user, full_name, and phone_number (only for new)
        if ($type === 'new' && $request->filled('full_name') && $request->filled('phone_number')) {
            $exists = \App\Models\DriverLicense::where([
                'user_id' => $userId,
                'full_name' => $request->full_name,
                'phone_number' => $request->phone_number,
            ])->where('status', '!=', 'rejected')->exists();
            if ($exists) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'A driver license with this full name and phone number already exists.'
                ], 409);
            }
        }
        
        // Prevent duplicate license number for lost/damaged licenses
        if ($type === 'lost_damaged' && $request->filled('license_number')) {
            $exists = \App\Models\DriverLicense::where([
                'license_number' => $request->license_number,
            ])->where('status', '!=', 'rejected')->exists();
            if ($exists) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'A driver license with this license number already exists.'
                ], 409);
            }
        }
        $license = \App\Models\DriverLicense::create($data);
        
        // Create notification for driver license creation
        NotificationService::notifyDriverLicenseOperation($userId, 'created', $license);
        
        return response()->json([
            'status' => 'success',
            'license' => $this->filterLicenseResponse($license)
        ]);
    }

    // Get all driver licenses for the authenticated user
    public function index()
    {
        $userId = Auth::user()->userId;
        $licenses = \App\Models\DriverLicense::where('user_id', $userId)
            ->orderByDesc('created_at')
            ->paginate(10);

        $current = $licenses->currentPage();
        $perPage = $licenses->perPage();

        $items = $licenses->getCollection()->values()->map(function($license, $index) use ($current, $perPage) {
            // Backfill missing slug
            if (empty($license->slug)) {
                $license->slug = (string) Str::uuid();
                $license->save();
            }
            $data = $this->filterLicenseResponse($license);
            // Add numeric id for pagination display
            $data['id'] = ($current - 1) * $perPage + ($index + 1);
            return $data;
        });

        return response()->json([
            'status' => 'success',
            'data' => $items,
            'pagination' => [
                'current_page' => $licenses->currentPage(),
                'per_page' => $licenses->perPage(),
                'total' => $licenses->total(),
                'last_page' => $licenses->lastPage(),
            ],
        ]);
    }

    // Initialize payment for a specific driver license (Monicredit integration)
    public function initializePaymentForLicense(Request $request, $slug)
    {
       

        $license = \App\Models\DriverLicense::where('slug', $slug)->first();
        if (!$license || $license->user_id !== Auth::user()->userId) {
            return response()->json(['status' => 'error', 'message' => 'License not found'], 404);
        }

        // Get payment option based on license type and year
        $option = null;
        $totalAmount = 0;
        $licenseYear = null;

        if ($license->license_type === 'new') {
            $licenseYear = $license->license_year;
            
            // Validate license year
            if (!in_array($licenseYear, [3, 5])) {
                return response()->json([
                    'status' => 'error', 
                    'message' => 'Invalid license year. Must be either 3 years or 5 years only.'
                ], 400);
            }

            // Get the specific payment option for new license with the selected year
            $optionType = 'new_' . $licenseYear . '_years';
            $option = DriverLicensePayment::where('type', $optionType)->first();
            
            if (!$option) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Payment option not found for ' . $licenseYear . ' years license.'
                ], 400);
            }
            
            $totalAmount = $option->amount;
        } else {
            // For renew and lost_damaged, use the existing logic
            $option = DriverLicensePayment::where('type', $license->license_type)->first();
            
            if (!$option) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid license type for payment'
                ], 400);
            }
            
            $totalAmount = $option->amount;
        }

        // Security: Ensure license is not already paid for
        if ($license->status === 'active') {
            return response()->json([
                'status' => 'error',
                'message' => 'This license has already been paid for and is active.'
            ], 400);
        }

        // Security: Check for existing completed payments for this license
        $existingCompletedPayment = \App\Models\Payment::where('meta_data->driver_license_id', $license->id)
            ->where('status', 'completed')
            ->first();
            
        if ($existingCompletedPayment) {
            return response()->json([
                'status' => 'error',
                'message' => 'This license has already been paid for and is active.'
            ], 400);
        }

        $user = Auth::user();
        $transaction_id = Str::random(10);
        
        // Use the full driver license record as meta_data
        $metaData = $license->toArray();
        $metaData['payment_option'] = $option->toArray();
        $metaData['total_amount'] = $totalAmount;
        if ($licenseYear) {
            $metaData['license_year'] = $licenseYear;
        }
        
        // Build item description
        $itemDescription = $option->name;
        
        $items = [[
            'unit_cost' => $totalAmount,
            'item' => $itemDescription,
            'revenue_head_code' => $option->revenue_head_code,
        ]];
        
        $payload = [
            'order_id' => $transaction_id,
            'public_key' => env('MONICREDIT_PUBLIC_KEY'),
            'customer' => [
                'first_name' => $user->name,
                'last_name' => '',
                'email' => $user->email,
                'phone' => $user->phone_number,
            ],
            'fee_bearer' => 'merchant',
            'items' => $items,
            'currency' => 'NGN',
            'paytype' => 'inline',
            'total_amount' => $totalAmount,
            'meta_data' => $metaData,
        ];
        
        // Check if MONICREDIT_BASE_URL is configured
        $baseUrl = env('MONICREDIT_BASE_URL');
        if (empty($baseUrl)) {
            return response()->json([
                'status' => false,
                'message' => 'Payment gateway configuration error. Please contact support.',
                'error' => 'MONICREDIT_BASE_URL not configured'
            ], 500);
        }

        try {
            $response = Http::timeout(30)->post($baseUrl . '/payment/transactions/init-transaction', $payload);
            $data = $response->json();
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Payment gateway connection error. Please try again later.',
                'error' => $e->getMessage()
            ], 500);
        }
        
        // Save transaction
        $txn = DriverLicenseTransaction::create([
            'transaction_id' => $transaction_id,
            'amount' => $totalAmount,
            'driver_license_id' => $license->id,
            'status' => 'pending',
            'reference_code' => $data['id'] ?? null,
            'payment_description' => $itemDescription,
            'user_id' => $user->id,
            'raw_response' => $data,
            'meta_data' => json_encode($metaData),
        ]);

        // Also create a unified Payment record for consolidated verification
        $unifiedPayment = \App\Models\Payment::create([
            'transaction_id' => $transaction_id,
            'gateway_reference' => $data['id'] ?? null,
            'amount' => $totalAmount,
            'user_id' => $user->id,
            'driver_license_id' => $license->id,
            'payment_gateway' => 'monicredit',
            'status' => 'pending',
            'payment_description' => $itemDescription,
            'raw_response' => $data,
            'payment_schedule_id' => [],
            'meta_data' => [
                'driver_license_id' => $license->id,
                'driver_license_slug' => $license->slug,
                'license_type' => $license->license_type,
                'payment_type' => 'driver_license',
                'revenue_head_code' => $option->revenue_head_code,
                'total_amount' => $totalAmount,
                'license_year' => $licenseYear,
                'payment_option_type' => $option->type,
            ],
        ]);
        
        return response()->json([
            'message' => 'Payment initialized successfully',
            'data' => $data,
        ]);
    }

    // Note: verifyPaymentForLicense method removed - now using unified payment verification endpoint
    // Use: POST /api/payment/verify-payment/{transaction_id}

    /**
     * Initialize Paystack payment for driver license
     */
    public function initializePaystackPaymentForLicense(Request $request, $slug)
    {
        $user = Auth::user();
        
        // Validate license ownership
        $license = \App\Models\DriverLicense::where('slug', $slug)
            ->where('user_id', $user->userId)
            ->first();
            
        if (!$license) {
            return response()->json([
                'status' => false,
                'message' => 'License not found or access denied'
            ], 404);
        }

        // Get payment option based on license type and year
        $option = null;
        $totalAmount = 0;
        $licenseYear = null;

        if ($license->license_type === 'new') {
            $licenseYear = $license->license_year;
            
            // Validate license year
            if (!in_array($licenseYear, [3, 5])) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid license year. Must be either 3 years or 5 years only.'
                ], 400);
            }

            // Get the specific payment option for new license with the selected year
            $optionType = 'new_' . $licenseYear . '_years';
            $option = DriverLicensePayment::where('type', $optionType)->first();
            
            if (!$option) {
                return response()->json([
                    'status' => false,
                    'message' => 'Payment option not found for ' . $licenseYear . ' years license.'
                ], 400);
            }
            
            $totalAmount = $option->amount;
        } else {
            // For renew and lost_damaged
            $option = DriverLicensePayment::where('type', $license->license_type)->first();
            
            if (!$option) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid license type for payment'
                ], 400);
            }
            
            $totalAmount = $option->amount;
        }

        // Security: Ensure license is not already paid for
        if ($license->status === 'active') {
            return response()->json([
                'status' => false,
                'message' => 'This license has already been paid for and is active.'
            ], 400);
        }

        // Security: Check for existing completed payments
        $existingCompletedPayment = \App\Models\Payment::where('meta_data->driver_license_id', $license->id)
            ->where('status', 'completed')
            ->first();
            
        if ($existingCompletedPayment) {
            return response()->json([
                'status' => false,
                'message' => 'This license has already been paid for and is active.'
            ], 400);
        }

        $transaction_id = Str::random(10);

        // Check if Paystack secret key is configured
        $secretKey = env('PAYSTACK_SECRET_KEY');
        if (empty($secretKey)) {
            return response()->json([
                'status' => false,
                'message' => 'Payment gateway configuration error. Please contact support.',
                'error' => 'PAYSTACK_SECRET_KEY not configured'
            ], 500);
        }

        // Build item description
        $itemDescription = $option->name;

        // Create unified Payment record
        $payment = \App\Models\Payment::create([
            'transaction_id' => $transaction_id,
            'amount' => $totalAmount,
            'user_id' => $user->id,
            'driver_license_id' => $license->id,
            'payment_gateway' => 'paystack',
            'status' => 'pending',
            'payment_description' => $itemDescription,
            'payment_schedule_id' => [],
            'meta_data' => [
                'driver_license_id' => $license->id,
                'driver_license_slug' => $license->slug,
                'license_type' => $license->license_type,
                'payment_type' => 'driver_license',
                'revenue_head_code' => $option->revenue_head_code,
                'license_data' => $license->toArray(),
                'total_amount' => $totalAmount,
                'license_year' => $licenseYear,
                'payment_option_type' => $option->type,
            ],
        ]);

        // Also create DriverLicenseTransaction
        $txn = DriverLicenseTransaction::create([
            'transaction_id' => $transaction_id,
            'amount' => $totalAmount,
            'driver_license_id' => $license->id,
            'status' => 'pending',
            'payment_description' => $itemDescription,
            'user_id' => $user->id,
            'meta_data' => json_encode(array_merge($license->toArray(), [
                'total_amount' => $totalAmount,
                'license_year' => $licenseYear,
            ])),
        ]);

        // Prepare Paystack payload
        $currentDomain = $request->getSchemeAndHttpHost();
        $payload = [
            'email' => $user->email,
            'amount' => $totalAmount * 100, // Paystack expects amount in kobo
            'reference' => $transaction_id,
            'currency' => 'NGN',
            'callback_url' => $currentDomain . '/payment/paystack/callback',
            // 'callback_url' => env('FRONTEND_URL', 'https://motoka.vercel.app') . '/payment/paystack/callback',
            'metadata' => [
                'user_id' => $user->id,
                'driver_license_id' => $license->id,
                'driver_license_slug' => $license->slug,
                'license_type' => $license->license_type,
                'payment_type' => 'driver_license',
                'revenue_head_code' => $option->revenue_head_code,
                'payment_description' => $itemDescription,
                'total_amount' => $totalAmount,
                'license_year' => $licenseYear,
                'payment_option_type' => $option->type,
            ],
        ];

        // Add customer information
        if ($user->name) {
            $nameParts = explode(' ', trim($user->name));
            $payload['first_name'] = $nameParts[0] ?? '';
            $payload['last_name'] = isset($nameParts[1]) ? implode(' ', array_slice($nameParts, 1)) : '';
        }

        if ($user->phone_number) {
            $payload['phone'] = $user->phone_number;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $secretKey,
                'Content-Type' => 'application/json',
            ])->timeout(30)->post('https://api.paystack.co/transaction/initialize', $payload);

            $data = $response->json();

            if (!$response->successful() || !$data['status']) {
                return response()->json([
                    'status' => false,
                    'message' => 'Payment initialization failed. Please try again.',
                    'error' => $data['message'] ?? 'Unknown error'
                ], 500);
            }

            // Update payment record
            $payment->update([
                'raw_response' => $data,
                'gateway_reference' => $data['data']['reference'] ?? null,
                'gateway_authorization_url' => $data['data']['authorization_url'] ?? null,
            ]);

            // Update DriverLicenseTransaction
            $txn->update([
                'reference_code' => $data['data']['reference'] ?? null,
                'raw_response' => $data,
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Payment initialized successfully',
                'data' => [
                    'authorization_url' => $data['data']['authorization_url'],
                    'access_code' => $data['data']['access_code'],
                    'reference' => $data['data']['reference'],
                    'transaction_id' => $transaction_id,
                    'amount' => $totalAmount,
                    'payment_gateway' => 'paystack',
                    'license' => [
                        'slug' => $license->slug,
                        'license_type' => $license->license_type,
                        'full_name' => $license->full_name ?? null,
                        'license_year' => $licenseYear,
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Paystack driver license payment initialization failed', [
                'error' => $e->getMessage(),
                'license_slug' => $slug,
                'user_id' => $user->id
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Payment gateway connection error. Please try again later.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show($slug)
    {
        $userId= Auth::user()->userId;
        $license = DriverLicense::where(['slug'=>$slug,'user_id'=>$userId])->first();
        if ($license) {
            return response()->json(["status"=> true, "data" => $this->filterLicenseResponse($license)], 200);
        }
        return response()->json(["status"=> false, "message"=> "License not found"], 401);

    }

     /**
     * Filter license response fields based on license type
     */
    private function filterLicenseResponse($license) {
        $data = $license->toArray();
        if ($license->license_type === 'renew') {
            // Only show fields relevant to renew
            return [
                'slug' => $license->slug,
                'user_id' => $license->user_id,
                'license_type' => $license->license_type,
                'status' => $license->status,
                'expired_license_upload' => $license->expired_license_upload ?? null,
                'created_at' => $license->created_at,
                'updated_at' => $license->updated_at,
            ];
        } elseif ($license->license_type === 'lost_damaged') {
            // Only show fields relevant to lost/damaged
            return [
                'slug' => $license->slug,
                'user_id' => $license->user_id,
                'license_type' => $license->license_type,
                'status' => $license->status,
                'license_number' => $license->license_number,
                'date_of_birth' => $license->date_of_birth,
                'created_at' => $license->created_at,
                'updated_at' => $license->updated_at,
            ];
        } elseif ($license->license_type === 'new') {
            // Only show fields relevant to new
            return [
                'slug' => $license->slug,
                'user_id' => $license->user_id,
                'license_type' => $license->license_type,
                'status' => $license->status,
                'full_name' => $license->full_name,
                'phone_number' => $license->phone_number,
                'address' => $license->address,
                'date_of_birth' => $license->date_of_birth,
                'place_of_birth' => $license->place_of_birth,
                'state_of_origin' => $license->state_of_origin,
                'local_government' => $license->local_government,
                'blood_group' => $license->blood_group,
                'height' => $license->height,
                'occupation' => $license->occupation,
                'next_of_kin' => $license->next_of_kin,
                'next_of_kin_phone' => $license->next_of_kin_phone,
                'mother_maiden_name' => $license->mother_maiden_name,
                'license_year' => $license->license_year,
                'passport_photograph' => $license->passport_photograph,
                'created_at' => $license->created_at,
                'updated_at' => $license->updated_at,
            ];
        } else {
            // Fallback: show all fields
            return $data;
        }
    }

    // New: Get all driver license payment options
    public function getPaymentOptions()
    {
        $options = DriverLicensePayment::all(['type', 'name', 'amount']);
        return response()->json([
            'status' => 'success',
            'data' => $options
        ]);
    }

    // New: Initialize driver license payment (stub)
    public function initializePayment(Request $request)
    {
        $request->validate([
            'type' => 'required|in:new,renew,lost_damaged',
            // Add other required fields for license registration here
        ]);
        $option = DriverLicensePayment::where('type', $request->type)->first();
        if (!$option) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid driver license payment type.'
            ], 400);
        }
        // Here you would start the Monicredit payment process using $option->amount and $option->name
        // For now, just return the looked-up payment option and input data
        return response()->json([
            'status' => 'success',
            'payment_option' => $option,
            'input' => $request->all()
        ]);
    }

    // Get driver license payment receipt
    public function getDriverLicenseReceipt(Request $request, $license_id)
    {
        $user = Auth::user();
        $license = \App\Models\DriverLicense::where('slug', $license_id)->first();
        if (!$license || $license->user_id !== $user->userId) {
            return response()->json([
                'status' => false,
                'message' => 'License not found.'
            ], 404);
        }
        $txn = DriverLicenseTransaction::where('driver_license_id', $license->id)
            ->where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->first();
        if (!$txn) {
            return response()->json([
                'status' => false,
                'message' => 'No payment found for this license.'
            ], 404);
        }
        return response()->json([
            'status' => true,
            'message' => 'Payment receipt found.',
            'payment' => $txn,
            'monicredit_response' => $txn->raw_response
        ]);
    }

    // Endpoint to get all seeded driver license payment options
    public function getDriverLicensePaymentOptions()
    {
        $options = \App\Models\DriverLicensePayment::all(['type', 'name', 'amount', 'revenue_head_code']);
        return response()->json([
            'status' => 'success',
            'data' => $options
        ]);
    }

    // Endpoint to list all driver license payment transactions/receipts for the authenticated user
    public function listAllDriverLicenseReceipts(Request $request)
    {
        $user = Auth::user();
        $transactions = \App\Models\DriverLicenseTransaction::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        $current = $transactions->currentPage();
        $perPage = $transactions->perPage();

        $receipts = $transactions->getCollection()->values()->map(function($txn, $index) use ($current, $perPage) {
            // Fetch license slug for this transaction
            $license = DriverLicense::find($txn->driver_license_id);
            $licenseSlug = $license ? ($license->slug ?: null) : null;
            // Ensure we return a UUID-like slug for the receipt; generate if missing in the row (response only)
            $receiptSlug = property_exists($txn, 'slug') && !empty($txn->slug) ? (string) $txn->slug : (string) Str::uuid();
            return [
                'id' => ($current - 1) * $perPage + ($index + 1),
                'slug' => $receiptSlug,
                'transaction_id' => $txn->transaction_id,
                'amount' => $txn->amount,
                'status' => $txn->status,
                'payment_description' => $txn->payment_description,
                'created_at' => $txn->created_at,
                'driver_license_slug' => $licenseSlug,
                'raw_response' => $txn->raw_response,
            ];
        });

        return response()->json([
            'status' => true,
            'receipts' => $receipts,
            'pagination' => [
                'current_page' => $transactions->currentPage(),
                'per_page' => $transactions->perPage(),
                'total' => $transactions->total(),
                'last_page' => $transactions->lastPage(),
            ]
        ]);
    }

    // Update a driver license (only if status is unpaid or rejected)
    public function update(Request $request, $slug)
    {
        $userId = Auth::user()->userId;
        $license = \App\Models\DriverLicense::where('slug', $slug)->where('user_id', $userId)->first();
        if (!$license) {
            return response()->json(['status' => 'error', 'message' => 'License not found'], 404);
        }
        if (!in_array($license->status, ['unpaid', 'rejected'])) {
            return response()->json(['status' => 'error', 'message' => 'Only unpaid or rejected licenses can be edited'], 403);
        }

        // Require date_of_birth for verification
        $request->validate([
            'date_of_birth' => 'required|date'
        ]);

        if ($request->date_of_birth !== $license->date_of_birth) {
            return response()->json(['status' => 'error', 'message' => 'Date of birth does not match. Update not allowed.'], 403);
        }

        // Allow partial update of any other fields except date_of_birth
        $license->fill($request->except(['date_of_birth']));
        $license->save();

        // Create notification for driver license update
        NotificationService::notifyDriverLicenseOperation($userId, 'updated', $license);

        return response()->json(['status' => 'success', 'license' => $license]);
    }

    // Delete a driver license (only if status is unpaid or rejected)
    public function destroy($slug)
    {
        $userId = Auth::user()->userId;
        $license = \App\Models\DriverLicense::where('slug', $slug)->where('user_id', $userId)->first();
        if (!$license) {
            return response()->json(['status' => 'error', 'message' => 'License not found'], 404);
        }
        if (!in_array($license->status, ['unpaid', 'rejected'])) {
            return response()->json(['status' => 'error', 'message' => 'Only unpaid or rejected licenses can be deleted'], 403);
        }
        
        // Store license info before deletion for notification
        $licenseInfo = $license->toArray();
        
        $license->delete();
        
        // Create notification for driver license deletion
        NotificationService::notifyDriverLicenseOperation($userId, 'deleted', (object)$licenseInfo);
        
        return response()->json(['status' => 'success', 'message' => 'License deleted successfully']);
    }
}
