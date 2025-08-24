<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\PaymentSchedule;
// use Faker\Provider\ar_EG\Payment;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
  public function initializePayment(Request $request)
{
    $user = Auth::user();
    $transaction_id = Str::random(10);

    $request->validate([
        'car_id' => 'required|exists:cars,id',
        'payment_schedule_id' => 'required|exists:payment_schedules,id',
        'meta_data' => 'nullable|array',
    ]);

    $car = \App\Models\Car::find($request->car_id);
    $getPaymentSchedule = PaymentSchedule::with(['payment_head', 'revenue_head'])->find($request->payment_schedule_id);
    if (!$getPaymentSchedule) {
        return response()->json(['message' => 'Invalid payment_schedule_id'], 400);
    }
    if (!$getPaymentSchedule->payment_head || !$getPaymentSchedule->revenue_head) {
        return response()->json(['message' => 'Payment schedule is missing payment head or revenue head'], 400);
    }

    // --- Car type/payment schedule validation ---
    // Define allowed payment_schedule_ids for each car_type
    $privateScheduleIds = [1, 2, 3, 4]; // Update with your actual IDs for private
    $commercialScheduleIds = [1, 2, 3, 4, 5]; // Update with your actual IDs for commercial
    if ($car->car_type === 'private' && !in_array($request->payment_schedule_id, $privateScheduleIds)) {
        return response()->json([
            'status' => false,
            'message' => 'Invalid payment_schedule_id for private car type.'
        ], 400);
    }
    if ($car->car_type === 'commercial' && !in_array($request->payment_schedule_id, $commercialScheduleIds)) {
        return response()->json([
            'status' => false,
            'message' => 'Invalid payment_schedule_id for commercial car type.'
        ], 400);
    }
    // --- End car type/payment schedule validation ---

    // Access control: Only allow payment for cars owned by the user
    if ($car->user_id !== $user->id && $car->user_id !== $user->userId) {
        return response()->json([
            'status' => false,
            'message' => 'Nice try, hacker! This car does not belong to you.'
        ], 403);
    }

    // Determine state_id and lga_id for delivery fee lookup
    $metaData = $request->meta_data ?? [];
    $state_id = $metaData['state_id'] ?? $car->state_id;
    $lga_id = $metaData['lga_id'] ?? $car->lga_id;

    // Fetch delivery fee from DeliveryFee model based on state_id and lga_id
    $deliveryFee = \App\Models\DeliveryFee::where('state_id', $state_id)
        ->where('lga_id', $lga_id)
        ->value('fee');

    if ($deliveryFee === null) {
        return response()->json([
            'status' => false,
            'message' => 'No delivery fee set for this car location. Please contact support.',
        ], 400);
    }

    // Calculate total amount: payment schedule amount + delivery fee
    $baseAmount = $getPaymentSchedule->amount;
    $totalAmount = $baseAmount + $deliveryFee;

    $items = [
        [
            'unit_cost' => $totalAmount,
            'item' => $getPaymentSchedule->payment_head->payment_head_name . ' + Delivery',
            'revenue_head_code' => $getPaymentSchedule->revenue_head->revenue_head_code,
        ]
    ];

    // Split user name for Monicredit customer
    $nameParts = explode(' ', trim($user->name));
    $firstName = $nameParts[0] ?? '';
    $lastName = isset($nameParts[1]) ? implode(' ', array_slice($nameParts, 1)) : $firstName;

    // Remove delivery_fee from meta_data if present, then add the correct value
    $metaData = $request->meta_data ?? [];
    unset($metaData['delivery_fee']);
    $metaData['delivery_fee'] = $deliveryFee;

    $payload = [
        'order_id' => $transaction_id,
        'public_key' => env('MONICREDIT_PUBLIC_KEY'),
        'customer' => [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $user->email,
            'phone' => $user->phone_number,
        ],
        'fee_bearer' => 'merchant', // Merchant pays Monicredit charges
        'items' => $items,
        'currency' => 'NGN',
        'paytype' => 'inline',
        'total_amount' => $totalAmount,
        'meta_data' => $metaData,
    ];
    if (!empty($user->nin)) {
        $payload['customer']['nin'] = $user->nin;
    }

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
        // Log::error('Monicredit API error', [
        //     'error' => $e->getMessage(),
        //     'url' => $baseUrl . '/payment/transactions/init-transaction',
        //     'payload' => $payload
        // ]);
        
        return response()->json([
            'status' => false,
            'message' => 'Payment gateway connection error. Please try again later.',
            'error' => $e->getMessage()
        ], 500);
    }

    // Log Monicredit response for debugging
    // Log::info('Monicredit payment initiation response', ['response' => $data]);

    // If Monicredit returns a payment error (not just customer error), show it
    if (isset($data['status']) && $data['status'] === false && isset($data['message'])) {
        return response()->json([
            'message' => 'Payment initiation failed',
            'monicredit_response' => $data,
            'car' => $car,
        ], 400);
    }

    $save = Payment::create([
        'transaction_id' => $transaction_id,
        'amount' => $totalAmount,
        'payment_schedule_id' => $request->payment_schedule_id,
        'car_id' => $car->id,
        'status' => 'pending',
        'reference_code' => $data['id'] ?? null,
        'payment_description' => $items[0]['item'],
        'user_id' => $user->id,
        'raw_response' => $data,
        'meta_data' => json_encode($metaData),
    ]);
    // Parse meta_data from payment record for top-level response
    $parsedMetaData = json_decode($save->meta_data, true);
    return response()->json([
        'message' => 'Payment initialized successfully',
        'data' => $data,
        'car' => $car,
        'payment' => $save,
        'meta_data' => $parsedMetaData,
        'delivery_fee' => $deliveryFee
    ]);
}

public function verifyPayment($transaction_id)
{
    $user = Auth::user();
    
    // EARLY ACCESS CONTROL: Check if user owns this transaction BEFORE making API call
    $payment = Payment::where('transaction_id', $transaction_id)->first();
    
    if (!$payment) {
        return response()->json([
            'status' => false,
            'message' => 'Payment record not found'
        ], 404);
    }
    
    // Access control: Only allow user to verify their own payment
    if ($payment->user_id !== $user->id && $payment->user_id !== $user->userId) {
        // Log::warning('Unauthorized payment verification attempt', [
        //     'user_id' => $user->id,
        //     'user_userId' => $user->userId,
        //     'payment_user_id' => $payment->user_id,
        //     'transaction_id' => $transaction_id,
        //     'ip' => request()->ip()
        // ]);
        
        return response()->json([
            'status' => false,
            'message' => 'You are not authorized to verify this payment'
        ], 403);
    }
    
    // Check if MONICREDIT_BASE_URL is configured
    $baseUrl = env('MONICREDIT_BASE_URL');
    if (empty($baseUrl)) {
        return response()->json([
            'status' => false,
            'message' => 'Payment gateway configuration error. Please contact support.',
            'error' => 'MONICREDIT_BASE_URL not configured'
        ], 500);
    }

    // Call Monicredit verification API
    try {
        $response = Http::timeout(30)->post($baseUrl . "/payment/transactions/verify-transaction", [
            'transaction_id' => $transaction_id,
            'private_key' => env('MONICREDIT_PRIVATE_KEY')
        ]);

        if (!$response->ok()) {
            // Log::error('Monicredit API returned non-OK status', [
            //     'status_code' => $response->status(),
            //     'response' => $response->body(),
            //     'transaction_id' => $transaction_id
            // ]);
            return response()->json([
                'status' => false,
                'message' => 'Payment verification failed'
            ], 500);
        }
    } catch (\Exception $e) {
        // Log::error('Monicredit verification API error', [
        //     'error' => $e->getMessage(),
        //     'url' => $baseUrl . "/payment/transactions/verify-transaction",
        //     'transaction_id' => $transaction_id
        // ]);
        
        return response()->json([
            'status' => false,
            'message' => 'Payment verification connection error. Please try again later.',
            'error' => $e->getMessage()
        ], 500);
    }

    $data = $response->json();
    
    // Log the response for debugging
    // Log::info('Monicredit verification response', [
    //     'transaction_id' => $transaction_id,
    //     'response' => $data
    // ]);

    // Update payment status regardless of the result
    $payment->update([
        'status' => strtolower($data['data']['status'] ?? 'unknown'),
        'raw_response' => $data
    ]);

    // Check if payment is successful (approved or success)
    if (isset($data['status']) && $data['status'] == true && 
        (strtolower($data['data']['status'] ?? '') === 'approved' || 
         strtolower($data['data']['status'] ?? '') === 'success')) {

        // If payment is successful and for a car, update car expiry and reminder
        $car = \App\Models\Car::find($payment->car_id);
        if ($car) {
            $car->status = 'active';
            $car->save();

            // Get the userId string from the user model
            $userModel = \App\Models\User::find($payment->user_id);
            $userIdString = $userModel ? $userModel->userId : null;

            if ($userIdString) {
                $reminderDate = \Carbon\Carbon::parse($car->expiry_date)->startOfDay();
                $nowDay = \Carbon\Carbon::now()->startOfDay();
                $daysLeft = $nowDay->diffInDays($reminderDate, false);

                if ($daysLeft > 30) {
                    \App\Models\Reminder::where('user_id', $userIdString)
                        ->where('type', 'car')
                        ->where('ref_id', $car->id)
                        ->delete();
                } else if ($daysLeft < 0) {
                    $message = 'License Expired.';
                    \App\Models\Reminder::updateOrCreate(
                        [
                            'user_id' => $userIdString,
                            'type' => 'car',
                            'ref_id' => $car->id,
                        ],
                        [
                            'message' => $message,
                            'remind_at' => $nowDay->format('Y-m-d H:i:s'),
                            'is_sent' => false
                        ]
                    );
                } else if ($daysLeft === 0) {
                    $message = 'Your car registration expires today! Please renew now.';
                    \App\Models\Reminder::updateOrCreate(
                        [
                            'user_id' => $userIdString,
                            'type' => 'car',
                            'ref_id' => $car->id,
                        ],
                        [
                            'message' => $message,
                            'remind_at' => $nowDay->format('Y-m-d H:i:s'),
                            'is_sent' => false
                        ]
                    );
                }
            }
        }

        return response()->json([
            'message' => 'Payment verified successfully',
            'data' => $data,
            'payment_date' => $payment->created_at,
            'car' => $car,
            'payment' => $payment
        ]);
    }

    // Payment not successful or still pending
    return response()->json([
        'message' => 'Payment not successful',
        'data' => $data
    ]);
}

public function getWalletInfo(Request $request)
{
    $user = Auth::user();
    $privateKey = env('MONICREDIT_PRIVATE_KEY');
    $headers = [
        'Accept' => 'application/json',
        'Authorization' => 'Bearer ' . $privateKey,
    ];

    $response = Http::withHeaders($headers)
        ->get(env('MONICREDIT_BASE_URL') . '/banking/wallet/account');

    $data = $response->json();
    // Log::info('Monicredit wallet list response', ['response' => $data]);

    // If Monicredit did not return a successful response, show the error message
    if (!(isset($data['status']) && $data['status'] === true) && !(isset($data['success']) && $data['success'] === true)) {
        $message = $data['message'] ?? 'Unable to fetch wallet info from Monicredit.';
        return response()->json([
            'status' => false,
            'message' => $message,
            'wallet' => [],
        ], 400);
    }

    // Filter for the authenticated user's wallet(s)
    $userWallets = [];
    if (isset($data['data']) && is_array($data['data'])) {
        foreach ($data['data'] as $wallet) {
            if (
                (isset($wallet['customer_email']) && $wallet['customer_email'] === $user->email) ||
                (isset($wallet['phone']) && $wallet['phone'] === $user->phone_number) ||
                (isset($wallet['customer_id']) && $wallet['customer_id'] === $user->monicredit_customer_id)
            ) {
                $userWallets[] = $wallet;
            }
        }
    }

    return response()->json([
        'status' => true,
        'wallet' => $userWallets
    ]);
}

public function getCarPaymentReceipt(Request $request, $car_id)
{
    $user = Auth::user();
    $car = \App\Models\Car::find($car_id);
    if (!$car) {
        return response()->json([
            'status' => false,
            'message' => 'Car not found.'
        ], 404);
    }
    if ($car->user_id !== $user->id && $car->user_id !== $user->userId) {
        return response()->json([
            'status' => false,
            'message' => 'This is not your car, hacker! Access denied.'
        ], 403);
    }
    $payment = \App\Models\Payment::where('car_id', $car_id)
        ->where('user_id', $user->id)
        ->orderBy('created_at', 'desc')
        ->first();
    if (!$payment) {
        return response()->json([
            'status' => false,
            'message' => 'No payment found for this car.'
        ], 404);
    }
    return response()->json([
        'status' => true,
        'message' => 'Payment receipt found.',
        'payment' => $payment,
        'monicredit_response' => $payment->raw_response
    ]);
}

public function getPaymentReceipt(Request $request, $payment_id)
{
    $user = Auth::user();
    
    // Find payment by UUID
    $payment = \App\Models\Payment::find($payment_id);
    if (!$payment) {
        return response()->json([
            'status' => false,
            'message' => 'Payment not found.'
        ], 404);
    }
    
    // Check if user owns this payment
    if ($payment->user_id !== $user->id && $payment->user_id !== $user->userId) {
        return response()->json([
            'status' => false,
            'message' => 'You are not authorized to view this payment receipt.'
        ], 403);
    }
    
    // Get car details
    $car = $payment->car;
    
    return response()->json([
        'status' => true,
        'message' => 'Payment receipt found.',
        'payment' => $payment,
        'car' => $car,
        'monicredit_response' => $payment->raw_response
    ]);
}

public function getAllReceipts(Request $request)
{
    $user = Auth::user();
    $userIds = [$user->id];
    if (isset($user->userId)) {
        $userIds[] = $user->userId;
    }
    $carIds = \App\Models\Car::whereIn('user_id', $userIds)->pluck('id');
    $transactions = \App\Models\Payment::with(['car', 'paymentSchedule.payment_head', 'paymentSchedule.revenue_head', 'paymentSchedule.gateway', 'paymentSchedule.revenue_head.bank'])
        ->whereIn('car_id', $carIds)
        ->orderBy('created_at', 'desc')
        ->get();

    $safeReceipts = $transactions->map(function($payment) {
        // Only expose safe fields
        $safe = [
            'id' => $payment->id,
            'transaction_id' => $payment->transaction_id,
            'amount' => $payment->amount,
            'status' => $payment->status,
            'payment_description' => $payment->payment_description,
            'created_at' => $payment->created_at,
            'car' => [
                'id' => $payment->car->id ?? null,
                'name_of_owner' => $payment->car->name_of_owner ?? null,
                'vehicle_make' => $payment->car->vehicle_make ?? null,
                'vehicle_model' => $payment->car->vehicle_model ?? null,
                'registration_no' => $payment->car->registration_no ?? null,
                'payment_date' => $payment->created_at,
            ],
            'payment_schedule' => [
                'id' => $payment->paymentSchedule->id ?? null,
                'amount' => $payment->paymentSchedule->amount ?? null,
                'payment_head' => $payment->paymentSchedule->payment_head->payment_head_name ?? null,
                'revenue_head' => $payment->paymentSchedule->revenue_head->revenue_head_name ?? null,
            ],
            'raw_response' => [
                'status' => $payment->raw_response['status'] ?? null,
                'message' => $payment->raw_response['message'] ?? null,
                'orderid' => $payment->raw_response['orderid'] ?? null,
                'data' => isset($payment->raw_response['data']) ? [
                    'amount' => $payment->raw_response['data']['amount'] ?? null,
                    'date_paid' => $payment->raw_response['data']['date_paid'] ?? null,
                    'status' => $payment->raw_response['data']['status'] ?? null,
                    'channel' => $payment->raw_response['data']['channel'] ?? null,
                ] : null,
            ],
        ];
        return $safe;
    });

    return response()->json([
        'status' => true,
        'receipts' => $safeReceipts
    ]);
}

public function deleteReceipt(Request $request, $payment_id)
{
    $user = Auth::user();
    $payment = \App\Models\Payment::find($payment_id);
    if (!$payment) {
        return response()->json([
            'status' => false,
            'message' => 'Payment not found.'
        ], 404);
    }
    $car = \App\Models\Car::find($payment->car_id);
    $userIds = [$user->id];
    if (isset($user->userId)) {
        $userIds[] = $user->userId;
    }
    if (!$car || !in_array($car->user_id, $userIds)) {
        return response()->json([
            'status' => false,
            'message' => 'You do not have permission to delete this receipt.'
        ], 403);
    }
    $payment->delete();
    return response()->json([
        'status' => true,
        'message' => 'Receipt deleted successfully.'
    ]);
}

public function getDeliveryFee(Request $request)
{
    $request->validate([
        'state_id' => 'required|integer|exists:states,id',
        'lga_id' => 'nullable|integer|exists:lgas,id',
    ]);

    $fee = \App\Models\DeliveryFee::where('state_id', $request->state_id)
        ->where(function($q) use ($request) {
            if ($request->lga_id) {
                $q->where('lga_id', $request->lga_id);
            } else {
                $q->whereNull('lga_id');
            }
        })
        ->orderByDesc('lga_id')
        ->value('fee');

    if ($fee === null) {
        return response()->json([
            'status' => false,
            'message' => 'No delivery fee set for this location.'
        ], 404);
    }

    return response()->json([
        'status' => true,
        'state_id' => $request->state_id,
        'lga_id' => $request->lga_id,
        'fee' => $fee
    ]);
}

public function listAllDeliveryFees()
{
    $fees = \App\Models\DeliveryFee::with(['state', 'lga'])->get();
    return response()->json([
        'status' => true,
        'data' => $fees
    ]);
}

public function listUserTransactions(Request $request)
{
    $user = Auth::user();
    $userIds = [$user->id];
    if (isset($user->userId)) {
        $userIds[] = $user->userId;
    }
    $carIds = \App\Models\Car::whereIn('user_id', $userIds)->pluck('id');
    $transactions = \App\Models\Payment::whereIn('car_id', $carIds)
        ->orderBy('created_at', 'desc')
        ->get();

    return response()->json([
        'status' => true,
        'transactions' => $transactions
    ]);
}




  
}
