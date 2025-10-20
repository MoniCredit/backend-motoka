<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\PaymentSchedule;
use App\Services\NotificationService;
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

    // Validation: car_slug is required, payment_schedule_id can be array for bulk payments
    $request->validate([
        'car_slug' => 'required|exists:cars,slug',
        'payment_schedule_id' => 'required', // Can be single ID or array
        'meta_data' => 'nullable|array',
    ]);

    // Resolve car strictly by slug
    $car = \App\Models\Car::where('slug', $request->car_slug)->first();

    if (!$car) {
        return response()->json([
            'message' => 'The selected car is invalid.',
            'errors' => [
                'car_slug' => ['The selected car is invalid.']
            ]
        ], 422);
    }

    // Handle both single and multiple payment schedules
    $paymentScheduleIds = is_array($request->payment_schedule_id) 
        ? $request->payment_schedule_id 
        : [$request->payment_schedule_id];

    // Validate all payment schedule IDs exist
    $paymentSchedules = PaymentSchedule::with(['payment_head', 'revenue_head'])
        ->whereIn('id', $paymentScheduleIds)
        ->get();

    if ($paymentSchedules->count() !== count($paymentScheduleIds)) {
        return response()->json(['message' => 'One or more payment schedule IDs are invalid'], 400);
    }

    // Validate all schedules have required relationships
    foreach ($paymentSchedules as $schedule) {
        if (!$schedule->payment_head || !$schedule->revenue_head) {
            return response()->json([
                'message' => 'Payment schedule ID ' . $schedule->id . ' is missing payment head or revenue head'
            ], 400);
        }
    }

    // --- Car type/payment schedule validation ---
    $privateScheduleIds = [1, 2, 3, 4]; // Update with your actual IDs for private
    $commercialScheduleIds = [1, 2, 3, 4, 5]; // Update with your actual IDs for commercial
    
    foreach ($paymentScheduleIds as $scheduleId) {
        if ($car->car_type === 'private' && !in_array($scheduleId, $privateScheduleIds)) {
        return response()->json([
            'status' => false,
                'message' => 'Invalid payment_schedule_id ' . $scheduleId . ' for private car type.'
        ], 400);
    }
        if ($car->car_type === 'commercial' && !in_array($scheduleId, $commercialScheduleIds)) {
        return response()->json([
            'status' => false,
                'message' => 'Invalid payment_schedule_id ' . $scheduleId . ' for commercial car type.'
        ], 400);
        }
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

    // Calculate total amount: sum of all payment schedule amounts + delivery fee
    $baseAmount = $paymentSchedules->sum('amount');
    $totalAmount = $baseAmount + $deliveryFee;

    // Create items array for all payment schedules
    $items = [];
    foreach ($paymentSchedules as $schedule) {
        $items[] = [
            'unit_cost' => $schedule->amount,
            'item' => $schedule->payment_head->payment_head_name,
            'revenue_head_code' => $schedule->revenue_head->revenue_head_code,
        ];
    }
    
    // Add delivery fee as a separate item
    if ($deliveryFee > 0) {
        $items[] = [
            'unit_cost' => $deliveryFee,
            'item' => 'Delivery Fee',
            'revenue_head_code' => 'REV68dff2878cb81', // Use the valid MOTOKA PAYMENT revenue head code
        ];
    }

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
        return response()->json([
            'status' => false,
            'message' => 'Payment gateway connection error. Please try again later.',
            'error' => $e->getMessage()
        ], 500);
    }

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
        'payment_schedule_id' => is_array($request->payment_schedule_id) 
            ? json_encode($request->payment_schedule_id) 
            : $request->payment_schedule_id,
        'car_id' => $car->id,
        'status' => 'pending',
        'payment_gateway' => 'monicredit',
        'reference_code' => $data['id'] ?? null,
        'payment_description' => count($paymentSchedules) > 1 
            ? 'Bulk Payment - ' . count($paymentSchedules) . ' items' 
            : $items[0]['item'],
        'user_id' => $user->id,
        'raw_response' => $data,
        'meta_data' => json_encode(array_merge($metaData, [
            'payment_schedule_ids' => $paymentScheduleIds,
            'payment_schedules' => $paymentSchedules->map(function($schedule) {
                return [
                    'id' => $schedule->id,
                    'amount' => $schedule->amount,
                    'payment_head_name' => $schedule->payment_head->payment_head_name,
                    'revenue_head_code' => $schedule->revenue_head->revenue_head_code,
                ];
            })->toArray(),
            'is_bulk_payment' => count($paymentScheduleIds) > 1,
        ])),
    ]);

    // Create notification for payment initiation
    NotificationService::notifyPaymentOperation($user->id, 'created', $save);
    // Parse meta_data from payment record for top-level response
    $parsedMetaData = json_decode($save->meta_data, true);
    return response()->json([
        'message' => 'Payment initialized successfully',
        'data' => $data,
        'car' => $car,
        'payment' => $this->formatPayment($save),
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

            // Handle different payment types
            $metaData = $payment->meta_data ?? [];
            $paymentType = $metaData['payment_type'] ?? 'car';

            if ($paymentType === 'driver_license') {
                // Handle driver license payment
                $this->handleDriverLicensePaymentSuccess($payment, $user, $metaData);
            } else {
                // Handle car payment (existing logic)
                $car = \App\Models\Car::find($payment->car_id);
                if ($car) {
                    $car->status = 'active';
                    $car->save();

                    // Create order for admin processing
                    $this->createOrderFromPayment($payment, $car, $user);
                    
                    // Debug: Log order creation for Monicredit payments
                    \Log::info('Monicredit payment processed - Order creation attempted', [
                        'payment_id' => $payment->id,
                        'payment_gateway' => $payment->payment_gateway,
                        'payment_status' => $payment->status,
                        'car_id' => $car->id,
                        'user_id' => $user->id,
                        'order_count' => \App\Models\Order::where('payment_id', $payment->id)->count()
                    ]);

                    // Get the userId string from the user model
                    $userModel = \App\Models\User::find($payment->user_id);
                    $userIdString = $userModel ? $userModel->userId : null;

                    if ($userIdString) {
                        // When payment is made, change status to "Processing"
                        $message = 'Processing';
                        \App\Models\Reminder::updateOrCreate(
                            [
                                'user_id' => $userIdString,
                                'type' => 'car',
                                'ref_id' => $car->id,
                            ],
                            [
                                'message' => $message,
                                'remind_at' => \Carbon\Carbon::now()->format('Y-m-d H:i:s'),
                                'is_sent' => false
                            ]
                        );
                    }

                    // Create appropriate notification based on payment type
                    $this->createPaymentNotification($user->id, $payment, $car);
                }
            }

            return response()->json([
                'status' => true,
                'message' => 'Payment verified successfully',
                'data' => [
                    'transaction_id' => $data['data']['transaction_id'] ?? null,
                    'status' => $data['data']['status'] ?? null,
                    'amount' => $data['data']['amount'] ?? null,
                ],
                'payment' => $this->formatPayment($payment)
            ]);
        }

        // Payment not successful or still pending - create notification
        NotificationService::notifyPaymentOperation($user->id, 'failed', $payment);
        
        return response()->json([
            'status' => false,
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

public function getCarPaymentReceipt(Request $request, $car_slug)
{
    $user = Auth::user();
    $car = \App\Models\Car::where('slug', $car_slug)->first();
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
    $payment = \App\Models\Payment::where('car_id', $car->id)
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
        'payment' => $this->formatPayment($payment),
        'monicredit_response' => $payment->raw_response
    ]);
}

public function getPaymentReceipt(Request $request, $payment_slug)
{
    $user = Auth::user();
    
    // Find payment by UUID
    $payment = \App\Models\Payment::where('slug', $payment_slug)->first();
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
        'payment' => $this->formatPayment($payment),
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
        ->paginate(10);

    $current = $transactions->currentPage();
    $perPage = $transactions->perPage();

    $receipts = $transactions->getCollection()->values()->map(function($payment, $index) use ($current, $perPage) {
        $numericId = ($current - 1) * $perPage + ($index + 1);
        return [
            'id' => $numericId,
            'slug' => $payment->slug,
            'transaction_id' => $payment->transaction_id,
            'amount' => $payment->amount,
            'status' => $payment->status,
            'payment_description' => $payment->payment_description,
            'created_at' => $payment->created_at,
            'car' => [
                'slug' => $payment->car->slug ?? null,
                'name_of_owner' => $payment->car->name_of_owner ?? null,
                'vehicle_make' => $payment->car->vehicle_make ?? null,
                'vehicle_model' => $payment->car->vehicle_model ?? null,
                'registration_no' => $payment->car->registration_no ?? null,
                'payment_date' => $payment->created_at,
            ],
            'payment_schedule' => [
                'id' => $payment->paymentSchedule?->id ?? null,
                'amount' => $payment->paymentSchedule?->amount ?? null,
                'payment_head' => $payment->paymentSchedule?->payment_head?->payment_head_name ?? null,
                'revenue_head' => $payment->paymentSchedule?->revenue_head?->revenue_head_name ?? null,
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

public function deleteReceipt(Request $request, $payment_slug)
{
    $user = Auth::user();
    $payment = \App\Models\Payment::where('slug', $payment_slug)->first();
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
        ->paginate(10);

    $current = $transactions->currentPage();
    $perPage = $transactions->perPage();

    $items = $transactions->getCollection()->values()->map(function($p, $index) use ($current, $perPage) {
        $numericId = ($current - 1) * $perPage + ($index + 1);
        return [
            'id' => $numericId,
            'slug' => $p->slug,
            'transaction_id' => $p->transaction_id,
            'amount' => $p->amount,
            'payment_schedule_id' => $p->payment_schedule_id,
            'car_id' => $p->car_id,
            'status' => $p->status,
            'reference_code' => $p->reference_code,
            'payment_description' => $p->payment_description,
            'user_id' => $p->user_id,
            'raw_response' => $p->raw_response,
            'meta_data' => $p->meta_data,
            'created_at' => $p->created_at,
            'updated_at' => $p->updated_at,
        ];
    });

    return response()->json([
        'status' => true,
        'transactions' => $items,
        'pagination' => [
            'current_page' => $transactions->currentPage(),
            'per_page' => $transactions->perPage(),
            'total' => $transactions->total(),
            'last_page' => $transactions->lastPage(),
        ]
    ]);
}

/**
 * Create order from successful payment
 */
private function createOrderFromPayment($payment, $car, $user)
{
    try {
        // Check if order already exists for this payment to prevent duplicates
        $existingOrder = \App\Models\Order::where('payment_id', $payment->id)->first();
        if ($existingOrder) {
            // Order already exists, no need to create another one
            return;
        }

        // Get payment schedule details
        $paymentSchedule = $payment->paymentSchedule;
        if (!$paymentSchedule) {
            \Log::error('Failed to create order - Payment schedule not found', [
                'payment_id' => $payment->id,
                'payment_schedule_id' => $payment->payment_schedule_id,
                'payment_gateway' => $payment->payment_gateway
            ]);
            return;
        }

        // Determine order type based on payment head
        $orderType = $this->getOrderTypeFromPaymentHead($paymentSchedule->payment_head->payment_head_name);

        // Get delivery address from payment metadata or car/user
        $metaData = is_string($payment->meta_data) ? json_decode($payment->meta_data, true) : $payment->meta_data;
        $deliveryAddress = $metaData['delivery_address'] ?? $car->address ?? $user->address ?? 'Address not provided';
        $deliveryContact = $metaData['delivery_contact'] ?? $user->phone_number ?? 'Contact not provided';
        $stateId = $metaData['state_id'] ?? null;
        $lgaId = $metaData['lga_id'] ?? null;

        // Create order
        $order = \App\Models\Order::create([
            'slug' => \Illuminate\Support\Str::uuid(),
            'user_id' => $user->id,
            'car_id' => $car->id,
            'payment_id' => $payment->id,
            'order_type' => $orderType,
            'status' => 'pending',
            'amount' => $payment->amount,
            'delivery_address' => $deliveryAddress,
            'delivery_contact' => $deliveryContact,
            'state' => $stateId,
            'lga' => $lgaId,
            'notes' => "Payment via {$payment->payment_gateway} - {$paymentSchedule->payment_head->payment_head_name}",
        ]);
        
        \Log::info('Order created successfully from payment', [
            'order_id' => $order->id,
            'payment_id' => $payment->id,
            'payment_gateway' => $payment->payment_gateway,
            'order_type' => $orderType
        ]);

    } catch (\Exception $e) {
        Log::error('Failed to create order from payment', [
            'payment_id' => $payment->id,
            'error' => $e->getMessage()
        ]);
    }
}

/**
 * Handle successful driver license payment
 */
    private function handleDriverLicensePaymentSuccess($payment, $user, $metaData)
    {
        try {
            $driverLicenseId = $metaData['driver_license_id'] ?? null;
            
            if (!$driverLicenseId) {
                \Log::error('Driver license ID not found in payment metadata', [
                    'payment_id' => $payment->id,
                    'meta_data' => $metaData
                ]);
                return;
            }

            // Security: Validate payment amount against license_year calculation
            $licenseYear = (float) ($metaData['license_year'] ?? 0);
            $baseAmount = (float) ($metaData['base_amount'] ?? 0);
            $expectedAmount = $baseAmount * $licenseYear;
            
            if ((float) $payment->amount != $expectedAmount) {
                \Log::error('Payment amount tampering detected', [
                    'payment_id' => $payment->id,
                    'expected_amount' => $expectedAmount,
                    'actual_amount' => $payment->amount,
                    'base_amount' => $baseAmount,
                    'license_year' => $licenseYear
                ]);
                
                // Mark payment as suspicious but still process
                $payment->update(['status' => 'suspicious']);
                return;
            }

        // Update driver license status
        $driverLicense = \App\Models\DriverLicense::find($driverLicenseId);
        if ($driverLicense) {
            $driverLicense->status = 'active';
            $driverLicense->save();

            // Update the corresponding DriverLicenseTransaction
            $driverLicenseTransaction = \App\Models\DriverLicenseTransaction::where('transaction_id', $payment->transaction_id)->first();
            if ($driverLicenseTransaction) {
                $driverLicenseTransaction->update([
                    'status' => 'approved',
                    'raw_response' => $payment->raw_response
                ]);
            }

            // Create notification for driver license payment completion
            \App\Services\NotificationService::notifyDriverLicenseOperation($user->userId, 'payment_completed', $driverLicense);

            \Log::info('Driver license payment processed successfully', [
                'payment_id' => $payment->id,
                'driver_license_id' => $driverLicenseId,
                'license_slug' => $driverLicense->slug,
                'user_id' => $user->id
            ]);
        } else {
            \Log::error('Driver license not found for payment', [
                'payment_id' => $payment->id,
                'driver_license_id' => $driverLicenseId
            ]);
        }
    } catch (\Exception $e) {
        \Log::error('Failed to handle driver license payment success', [
            'payment_id' => $payment->id,
            'error' => $e->getMessage()
        ]);
    }
}

/**
 * Check for existing payments for the same document type
 */
    public function checkExistingPayments(Request $request)
    {
        \Log::info('🔍 checkExistingPayments API called', [
            'request_data' => $request->all(),
            'user_authenticated' => Auth::check(),
            'user_id' => Auth::id()
        ]);

        $request->validate([
            'car_slug' => 'required|string',
            'payment_schedule_ids' => 'required|array',
            'payment_schedule_ids.*' => 'integer|exists:payment_schedules,id'
        ]);

        $user = Auth::user();
        
        \Log::info('👤 User details', [
            'user_id' => $user ? $user->id : null,
            'user_userId' => $user ? $user->userId : null
        ]);
    
    $car = \App\Models\Car::where('slug', $request->car_slug)
        ->where('user_id', $user->userId) 
        ->first();

    \Log::info('🚗 Car lookup result', [
        'car_slug' => $request->car_slug,
        'user_userId' => $user->userId,
        'car_found' => $car ? true : false,
        'car_id' => $car ? $car->id : null
    ]);

    if (!$car) {
        \Log::error('❌ Car not found', [
            'car_slug' => $request->car_slug,
            'user_userId' => $user->userId
        ]);
        return response()->json([
            'status' => false,
            'message' => 'Car not found'
        ], 404);
    }

    // Get payment schedule details
    $paymentSchedules = \App\Models\PaymentSchedule::whereIn('id', $request->payment_schedule_ids)
        ->with('payment_head')
        ->get();

    $existingPayments = [];
    $availableSchedules = [];

    foreach ($paymentSchedules as $schedule) {
        // Check if there's already a completed payment for this document type
        // Use a different approach since whereJsonContains might not work as expected
        $completedPayments = \App\Models\Payment::where('user_id', $user->id) // Payment table uses user ID
            ->where('car_id', $car->id)
            ->where('status', 'completed')
            ->get();
        
        $existingPayment = null;
        foreach ($completedPayments as $payment) {
            $scheduleIds = is_string($payment->payment_schedule_id) 
                ? json_decode($payment->payment_schedule_id, true) 
                : $payment->payment_schedule_id;
            
            if (is_array($scheduleIds) && in_array($schedule->id, $scheduleIds)) {
                $existingPayment = $payment;
                break;
            }
        }

        if ($existingPayment) {
            $existingPayments[] = [
                'payment_head_name' => $schedule->payment_head->payment_head_name,
                'payment_id' => $existingPayment->id,
                'amount' => $existingPayment->amount,
                'created_at' => $existingPayment->created_at,
                'status' => $existingPayment->status
            ];
        } else {
            $availableSchedules[] = $schedule;
        }
    }

        $response = [
            'status' => true,
            'data' => [
                'existing_payments' => $existingPayments,
                'available_schedules' => $availableSchedules,
                'has_duplicates' => count($existingPayments) > 0,
                'can_proceed' => count($availableSchedules) > 0
            ]
        ];

        \Log::info('✅ checkExistingPayments response', [
            'existing_payments_count' => count($existingPayments),
            'available_schedules_count' => count($availableSchedules),
            'has_duplicates' => count($existingPayments) > 0,
            'can_proceed' => count($availableSchedules) > 0
        ]);

        return response()->json($response);
}

/**
 * Get order type from payment head name
 */
private function getOrderTypeFromPaymentHead($paymentHeadName)
{
    $paymentHeadName = strtolower(trim($paymentHeadName));
    
    // Map exact payment head names to order types
    switch ($paymentHeadName) {
        case 'insurance':
            return 'insurance';
        case 'vehicle license':
            return 'license_renewal';
        case 'proof of ownership':
            return 'proof_of_ownership';
        case 'road wortiness':
            return 'road_worthiness';
        case 'hackney permit':
            return 'hackney_permit';
        default:
            // Fallback to keyword matching for any new payment heads
            if (strpos($paymentHeadName, 'license') !== false || strpos($paymentHeadName, 'renewal') !== false) {
                return 'license_renewal';
            } elseif (strpos($paymentHeadName, 'registration') !== false) {
                return 'vehicle_registration';
            } elseif (strpos($paymentHeadName, 'insurance') !== false) {
                return 'insurance';
            } elseif (strpos($paymentHeadName, 'inspection') !== false) {
                return 'inspection';
            } elseif (strpos($paymentHeadName, 'proof') !== false) {
                return 'proof_of_ownership';
            } elseif (strpos($paymentHeadName, 'road') !== false) {
                return 'road_worthiness';
            } elseif (strpos($paymentHeadName, 'hackney') !== false) {
                return 'hackney_permit';
            } else {
                return 'general_service';
            }
    }
}

/**
 * Create appropriate notification based on payment type
 */
private function createPaymentNotification($userId, $payment, $car)
{
    $isRenewal = false;
    $paymentHeadName = '';
    
    // Get payment schedule to determine the type of payment
    try {
        // Load the payment schedule with its payment head
        $paymentScheduleId = $payment->payment_schedule_id;
        
       
        if (is_array($paymentScheduleId)) {
            if (count($paymentScheduleId) === 0) {
              
                $paymentSchedule = null;
            } else {
                // For bulk payments, use the first schedule
                $paymentSchedule = PaymentSchedule::with('payment_head')->find($paymentScheduleId[0]);
            }
        } else {
            // Single payment schedule
            $paymentSchedule = PaymentSchedule::with('payment_head')->find($paymentScheduleId);
        }
        
        if ($paymentSchedule && $paymentSchedule->payment_head) {
            $paymentHeadName = strtolower($paymentSchedule->payment_head->payment_head_name);
            
            // Check if this is a car renewal payment
            if (strpos($paymentHeadName, 'license') !== false || 
                strpos($paymentHeadName, 'renewal') !== false ||
                strpos($paymentHeadName, 'vehicle license') !== false) {
                $isRenewal = true;
            }
        }
        
        // Also check payment description for renewal keywords
        if (!$isRenewal && $payment->payment_description) {
            $description = strtolower($payment->payment_description);
            if (strpos($description, 'license') !== false || 
                strpos($description, 'renewal') !== false ||
                strpos($description, 'vehicle license') !== false) {
                $isRenewal = true;
            }
        }
        
    } catch (\Exception $e) {
        // Log error but continue with generic notification
        \Log::error('Error determining payment type for notification', [
            'payment_id' => $payment->id,
            'error' => $e->getMessage()
        ]);
    }
    
    if ($isRenewal && $car) {
        // Create car renewal notification
        NotificationService::notifyCarOperation($userId, 'renewed', $car, "Payment of ₦" . number_format($payment->amount, 2) . " completed successfully.");
    } else {
        // Create generic payment notification
        NotificationService::notifyPaymentOperation($userId, 'completed', $payment);
    }
}

private function formatPayment(Payment $payment)
{
    return [
        'transaction_id' => $payment->transaction_id,
        'amount' => $payment->amount,
        'status' => $payment->status,
        'payment_gateway' => $payment->payment_gateway,
        'created_at' => $payment->created_at,
    ];
}

}
