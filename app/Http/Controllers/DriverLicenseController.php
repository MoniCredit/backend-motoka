<?php

namespace App\Http\Controllers;

use App\Models\DriverLicense;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
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
                'license_year' => 'required|integer',
                'passport_photograph' => 'required|image|mimes:jpeg,png,jpg|max:2048',
            ]);
        } elseif ($type === 'renew') {
            $rules = array_merge($baseRules, [
                'expired_license_upload' => 'required|file|mimes:jpeg,png,jpg,pdf',
                'full_name' => 'nullable|string',
                'date_of_birth' => 'nullable|date',
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
            $data['full_name'] = $request->full_name;
            $data['date_of_birth'] = $request->date_of_birth;
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
        $license = \App\Models\DriverLicense::create($data);
        return response()->json([
            'status' => 'success',
            'license' => $this->filterLicenseResponse($license)
        ]);
    }

    // Get all driver licenses for the authenticated user
    public function index()
    {
        $userId = Auth::user()->userId;
        $licenses = \App\Models\DriverLicense::where('user_id', $userId)->get();
        return response()->json([
            'status' => 'success',
            'data' => $licenses->map(fn($license) => $this->filterLicenseResponse($license))
        ]);
    }

    // Initialize payment for a specific driver license (Monicredit integration)
    public function initializePaymentForLicense(Request $request, $id)
    {
        $license = \App\Models\DriverLicense::find($id);
        if (!$license || $license->user_id !== Auth::user()->userId) {
            return response()->json(['status' => 'error', 'message' => 'License not found'], 404);
        }
        $option = DriverLicensePayment::where('type', $license->license_type)->first();
        if (!$option) {
            return response()->json(['status' => 'error', 'message' => 'Invalid license type for payment'], 400);
        }
        $user = Auth::user();
        $transaction_id = Str::random(10);
        // Use the full driver license record as meta_data
        $metaData = $license->toArray();
        $items = [[
            'unit_cost' => $option->amount,
            'item' => $option->name,
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
            'total_amount' => $option->amount,
            'meta_data' => $metaData,
        ];
        $response = Http::post(env('MONICREDIT_BASE_URL') . '/payment/transactions/init-transaction', $payload);
        $data = $response->json();
        // Save transaction
        $txn = DriverLicenseTransaction::create([
            'transaction_id' => $transaction_id,
            'amount' => $option->amount,
            'driver_license_id' => $license->id,
            'status' => 'pending',
            'reference_code' => $data['id'] ?? null,
            'payment_description' => $option->name,
            'user_id' => $user->id,
            'raw_response' => $data,
            'meta_data' => json_encode($metaData),
        ]);
        return response()->json([
            'message' => 'Payment initialized successfully',
            'data' => $data,
            // Optionally uncomment the next lines if frontend needs them:
            // 'license' => $license,
            // 'payment' => $txn,
            //'meta_data' => $metaData,
        ]);
    }

    // Verify payment for a specific license (Monicredit integration)
    public function verifyPaymentForLicense(Request $request, $id)
    {
        $license = \App\Models\DriverLicense::find($id);
        if (!$license || $license->user_id !== Auth::user()->userId) {
            return response()->json(['status' => 'error', 'message' => 'License not found'], 404);
        }
        $txn = DriverLicenseTransaction::where('driver_license_id', $license->id)->orderBy('created_at', 'desc')->first();
        if (!$txn) {
            return response()->json(['status' => 'error', 'message' => 'No payment transaction found'], 404);
        }
        $response = Http::post(env('MONICREDIT_BASE_URL') . '/payment/transactions/verify-transaction', [
            'transaction_id' => $txn->transaction_id,
            'private_key' => env('MONICREDIT_PRIVATE_KEY'),
        ]);
        $data = $response->json();
        if (isset($data['status']) && $data['status'] == true) {
            $txn->update([
                'status' => strtolower($data['data']['status']),
                'raw_response' => $data
            ]);
            if (strtolower($data['data']['status']) === 'approved' || strtolower($data['data']['status']) === 'success') {
                $license->status = 'active';
                $license->save();
            }
            return response()->json([
                'message' => 'Payment verified successfully',
                'data' => $data,
                // 'license' => $license,
                'payment' => $txn
            ]);
        }
        return response()->json([
            'message' => 'Payment not successful',
            'data' => $data
        ]);
    }

    public function show($id)
    {
        $userId= Auth::user()->userId;
        $license = DriverLicense::where(['id'=>$id,'user_id'=>$userId])->first();
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
                'id' => $license->id,
                'user_id' => $license->user_id,
                'license_type' => $license->license_type,
                'status' => $license->status,
                'expired_license_upload' => $license->expired_license_upload ?? null,
                'full_name' => $license->full_name,
                'date_of_birth' => $license->date_of_birth,
                'created_at' => $license->created_at,
                'updated_at' => $license->updated_at,
            ];
        } elseif ($license->license_type === 'lost_damaged') {
            // Only show fields relevant to lost/damaged
            return [
                'id' => $license->id,
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
                'id' => $license->id,
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
        $license = \App\Models\DriverLicense::find($license_id);
        if (!$license || $license->user_id !== $user->userId) {
            return response()->json([
                'status' => false,
                'message' => 'License not found.'
            ], 404);
        }
        $txn = DriverLicenseTransaction::where('driver_license_id', $license_id)
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
            ->get();
        $receipts = $transactions->map(function($txn) {
            return [
                'id' => $txn->id,
                'transaction_id' => $txn->transaction_id,
                'amount' => $txn->amount,
                'status' => $txn->status,
                'payment_description' => $txn->payment_description,
                'created_at' => $txn->created_at,
                'driver_license_id' => $txn->driver_license_id,
                'raw_response' => $txn->raw_response,
            ];
        });
        return response()->json([
            'status' => true,
            'receipts' => $receipts
        ]);
    }

    // Update a driver license (only if status is unpaid or rejected)
    public function update(Request $request, $id)
    {
        $userId = Auth::user()->userId;
        $license = \App\Models\DriverLicense::where('id', $id)->where('user_id', $userId)->first();
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

        return response()->json(['status' => 'success', 'license' => $license]);
    }

    // Delete a driver license (only if status is unpaid or rejected)
    public function destroy($id)
    {
        $userId = Auth::user()->userId;
        $license = \App\Models\DriverLicense::where('id', $id)->where('user_id', $userId)->first();
        if (!$license) {
            return response()->json(['status' => 'error', 'message' => 'License not found'], 404);
        }
        if (!in_array($license->status, ['unpaid', 'rejected'])) {
            return response()->json(['status' => 'error', 'message' => 'Only unpaid or rejected licenses can be deleted'], 403);
        }
        $license->delete();
        return response()->json(['status' => 'success', 'message' => 'License deleted successfully']);
    }
}
