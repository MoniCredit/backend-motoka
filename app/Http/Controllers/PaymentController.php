<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\PaymentSchedule;
// use Faker\Provider\ar_EG\Payment;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

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
            'nin' => $user->nin ?? '96380283356',
        ],
        'fee_bearer' => 'merchant', // Merchant pays Monicredit charges
        'items' => $items,
        'currency' => 'NGN',
        'paytype' => 'inline',
        'total_amount' => $totalAmount,
        'meta_data' => $metaData,
    ];

    $response = Http::post(env('MONICREDIT_BASE_URL') . '/payment/transactions/init-transaction', $payload);
    $data = $response->json();

    // Log Monicredit response for debugging
    \Log::info('Monicredit payment initiation response', ['response' => $data]);

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
    // dd($transaction_id);
    // Call Monicredit verification API
    $response = Http::post(env('MONICREDIT_BASE_URL') . "/payment/transactions/verify-transaction", [
        'transaction_id' => $transaction_id,
        'private_key' => env('MONICREDIT_PRIVATE_KEY')
    ]);

    if (!$response->ok()) {
        return response()->json(['message' => 'Verification failed'], 500);
    }

    $data = $response->json();

    // Check payment status
    if (isset($data['status']) && $data['status'] == true) {
        // Find payment by order_id or reference_code
        $payment = Payment::where('transaction_id', $data['orderid'])->first();

        if (!$payment) {
            return response()->json(['message' => 'Payment record not found'], 404);
        }
        // Access control: Only allow user to verify their own payment
        if ($payment->user_id !== $user->id && $payment->user_id !== $user->userId) {
            return response()->json([
                'status' => false,
                'message' => 'You are not the owner of this payment. Go hack somewhere else!'
            ], 403);
        }

        // Update payment status
        $payment->update([
            'status' => strtolower($data['data']['status']),
            'raw_response' => $data
        ]);

        return response()->json([
            'message' => 'Payment verified successfully',
            'data' => $data
        ]);
    }

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

    $response = \Http::withHeaders($headers)
        ->get(env('MONICREDIT_BASE_URL') . '/banking/wallet/account');

    $data = $response->json();
    \Log::info('Monicredit wallet list response', ['response' => $data]);

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
    $transactions = \App\Models\Payment::where('user_id', $user->id)
        ->orderBy('created_at', 'desc')
        ->get();

    return response()->json([
        'status' => true,
        'transactions' => $transactions
    ]);
}


  
}
