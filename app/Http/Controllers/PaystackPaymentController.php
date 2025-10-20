<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\PaymentSchedule;
use App\Services\NotificationService;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PaystackPaymentController extends Controller
{
    /**
     * Initialize Paystack payment for car renewal (single or bulk)
     */
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

        // Create payment record with bulk payment support
        $payment = Payment::create([
            'user_id' => $user->id,
            'car_id' => $car->id,
            'payment_schedule_id' => is_array($request->payment_schedule_id) 
                ? json_encode($request->payment_schedule_id) 
                : $request->payment_schedule_id,
            'amount' => $totalAmount,
            'transaction_id' => $transaction_id,
            'status' => 'pending',
            'payment_gateway' => 'paystack',
            'meta_data' => json_encode(array_merge($metaData, [
                'delivery_fee' => $deliveryFee,
                'base_amount' => $baseAmount,
                'car_slug' => $car->slug,
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

        // Prepare Paystack payload
        $payload = [
            'email' => $user->email,
            'amount' => $totalAmount * 100, // Paystack expects amount in kobo
            'reference' => $transaction_id,
            'currency' => 'NGN',
            'callback_url' => env('FRONTEND_URL', 'https://motoka.vercel.app') . '/payment/paystack/callback',
            'metadata' => [
                'user_id' => $user->id,
                'car_id' => $car->id,
                'car_slug' => $car->slug,
                'payment_schedule_ids' => $paymentScheduleIds,
                'delivery_fee' => $deliveryFee,
                'base_amount' => $baseAmount,
                'is_bulk_payment' => count($paymentScheduleIds) > 1,
                'payment_schedules' => $paymentSchedules->map(function($schedule) {
                    return [
                        'id' => $schedule->id,
                        'amount' => $schedule->amount,
                        'payment_head_name' => $schedule->payment_head->payment_head_name,
                        'revenue_head_code' => $schedule->revenue_head->revenue_head_code,
                    ];
                })->toArray(),
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

        // Check if Paystack secret key is configured
        $secretKey = env('PAYSTACK_SECRET_KEY');
        if (empty($secretKey)) {
            return response()->json([
                'status' => false,
                'message' => 'Payment gateway configuration error. Please contact support.',
                'error' => 'PAYSTACK_SECRET_KEY not configured'
            ], 500);
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $secretKey,
                'Content-Type' => 'application/json',
            ])->timeout(30)->post('https://api.paystack.co/transaction/initialize', $payload);

            $data = $response->json();

            if (!$response->successful() || !$data['status']) {
                // Log::error('Paystack payment initialization failed', [
                //     'response' => $data,
                //     'payload' => $payload,
                //     'user_id' => $user->id,
                //     'car_slug' => $car->slug
                // ]);

                return response()->json([
                    'status' => false,
                    'message' => 'Payment initialization failed. Please try again.',
                    'error' => $data['message'] ?? 'Unknown error'
                ], 500);
            }

            // Update payment record with Paystack response
            $payment->update([
                'raw_response' => $data,
                'gateway_reference' => $data['data']['reference'] ?? null,
                'gateway_authorization_url' => $data['data']['authorization_url'] ?? null,
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
                ]
            ]);

        } catch (\Exception $e) {
            // Log::error('Paystack payment initialization exception', [
            //     'error' => $e->getMessage(),
            //     'payload' => $payload,
            //     'user_id' => $user->id,
            //     'car_slug' => $car->slug
            // ]);

            return response()->json([
                'status' => false,
                'message' => 'Payment gateway connection error. Please try again later.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get Paystack reference by transaction ID
     */
    public function getPaystackReference(Request $request, $transactionId)
    {
        $user = Auth::user();
        
        try {
            // Find the payment record with the given transaction ID
            $payment = Payment::where('transaction_id', $transactionId)
                ->where('user_id', $user->id)
                ->where('payment_gateway', 'paystack')
                ->first();
            
            if (!$payment) {
                return response()->json([
                    'status' => false,
                    'message' => 'Payment record not found or you are not authorized to access this payment'
                ], 404);
            }
            
            if (!$payment->gateway_reference) {
                return response()->json([
                    'status' => false,
                    'message' => 'Paystack reference not found for this payment'
                ], 404);
            }
            
            return response()->json([
                'status' => true,
                'message' => 'Paystack reference found',
                'data' => [
                    'transaction_id' => $payment->transaction_id,
                    'gateway_reference' => $payment->gateway_reference,
                    'amount' => $payment->amount,
                    'status' => $payment->status
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to get Paystack reference',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Verify Paystack payment (Secured against IDOR)
     */
    public function verifyPayment(Request $request, $reference)
    {
        $user = Auth::user();
        
        // Check if Paystack secret key is configured
        $secretKey = env('PAYSTACK_SECRET_KEY');
        if (empty($secretKey)) {
            return response()->json([
                'status' => false,
                'message' => 'Payment gateway configuration error. Please contact support.',
                'error' => 'PAYSTACK_SECRET_KEY not configured'
            ], 500);
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $secretKey,
                'Content-Type' => 'application/json',
            ])->timeout(30)->get("https://api.paystack.co/transaction/verify/{$reference}");

            $data = $response->json();

            if (!$response->successful() || !$data['status']) {
                return response()->json([
                    'status' => false,
                    'message' => 'Payment verification failed',
                    'data' => $data
                ], 400);
            }

            // Find payment record with user authorization check
            $payment = Payment::where(function($query) use ($reference) {
                $query->where('transaction_id', $reference)
                      ->orWhere('gateway_reference', $reference);
            })
            ->where('user_id', $user->id) // IDOR Protection: Only allow user to verify their own payments
            ->first();

            if (!$payment) {
                // Log::warning('Unauthorized payment verification attempt', [
                //     'user_id' => $user->id,
                //     'reference' => $reference,
                //     'ip' => $request->ip()
                // ]);
                
                return response()->json([
                    'status' => false,
                    'message' => 'Payment record not found or you are not authorized to verify this payment'
                ], 404);
            }

            // Update payment record with verification response
            $payment->update([
                'raw_response' => $data,
                'status' => $data['data']['status'] === 'success' ? 'completed' : 'failed',
                'gateway_response' => $data['data'],
            ]);

            // Check if payment is successful
            if ($data['data']['status'] === 'success') {
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

                        // Create order for admin processing (only if not already created)
                        $this->createOrderFromPayment($payment, $car, $user);
                        
                        // Verify orders were created
                        $orderCount = \App\Models\Order::where('payment_id', $payment->id)->count();
                        if ($orderCount === 0) {
                            // If no orders created, try again with error logging
                            \Log::error('No orders created for payment, retrying...', [
                                'payment_id' => $payment->id,
                                'gateway_reference' => $payment->gateway_reference
                            ]);
                            $this->createOrderFromPayment($payment, $car, $user);
                        }

                        // Handle reminders - update to "Processing" status
                        $this->updateCarReminders($payment, $car);

                        // Create appropriate notification based on payment type
                        $this->createPaymentNotification($user->id, $payment, $car);
                    }
                }

                return response()->json([
                    'status' => true,
                    'message' => 'Payment verified successfully',
                    'data' => [
                        'reference' => $data['data']['reference'] ?? null,
                        'amount' => $data['data']['amount'] ?? null,
                        'status' => $data['data']['status'] ?? null,
                        'paid_at' => $data['data']['paid_at'] ?? null,
                        'channel' => $data['data']['channel'] ?? null,
                        'currency' => $data['data']['currency'] ?? null,
                    ],
                    'payment' => $this->formatPayment($payment)
                ]);
            }

            // Payment not successful - create notification
            NotificationService::notifyPaymentOperation($user->id, 'failed', $payment);
            
            return response()->json([
                'status' => false,
                'message' => 'Payment not successful',
                'data' => [
                    'reference' => $data['data']['reference'] ?? null,
                    'status' => $data['data']['status'] ?? null,
                ]
            ]);

        } catch (\Exception $e) {
            // Log::error('Paystack payment verification exception', [
            //     'error' => $e->getMessage(),
            //     'reference' => $reference
            // ]);

            return response()->json([
                'status' => false,
                'message' => 'Payment verification failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Handle Paystack callback (User redirect after payment)
     */
    public function handleCallback(Request $request)
    {
        // Paystack sends different parameter names in different scenarios
        $reference = $request->query('reference') ?? $request->query('trxref') ?? $request->input('reference');
        $status = $request->query('status') ?? $request->input('status');
        
        // Log::info('Paystack callback received', [
        //     'reference' => $reference,
        //     'status' => $status,
        //     'method' => $request->method(),
        //     'all_params' => $request->all(),
        //     'ip' => $request->ip()
        // ]);

        if (!$reference) {
            return response()->json([
                'status' => false,
                'message' => 'Missing payment reference',
                'debug' => [
                    'received_params' => $request->all(),
                    'method' => $request->method()
                ]
            ], 400);
        }

        // Find payment record
        $payment = Payment::where('transaction_id', $reference)
            ->orWhere('gateway_reference', $reference)
            ->first();

        if (!$payment) {
            return response()->json([
                'status' => false,
                'message' => 'Payment not found'
            ], 404);
        }

        // Return payment status for frontend to handle
        return response()->json([
            'status' => true,
            'message' => 'Payment status retrieved',
            'data' => [
                'reference' => $reference,
                'payment_status' => $payment->status,
                'amount' => $payment->amount,
                'car_slug' => $payment->car->slug ?? null,
                'redirect_url' => $this->getRedirectUrl($payment)
            ]
        ]);
    }

    /**
     * Get redirect URL based on payment status
     */
    private function getRedirectUrl($payment)
    {
        $baseUrl = env('FRONTEND_URL', env('APP_URL'));
        
        switch ($payment->status) {
            case 'completed':
                return $baseUrl . '/payment/success?ref=' . $payment->transaction_id;
            case 'failed':
                return $baseUrl . '/payment/failed?ref=' . $payment->transaction_id;
            case 'pending':
                return $baseUrl . '/payment/pending?ref=' . $payment->transaction_id;
            default:
                return $baseUrl . '/payment/status?ref=' . $payment->transaction_id;
        }
    }

    /**
     * Handle Paystack webhook (Secured with signature verification)
     */
    public function handleWebhook(Request $request)
    {
        $payload = $request->getContent();
        $signature = $request->header('X-Paystack-Signature');
        
        // Verify webhook signature
        if (!$this->verifyWebhookSignature($payload, $signature)) {
            // Log::warning('Invalid Paystack webhook signature', [
            //     'ip' => $request->ip(),
            //     'user_agent' => $request->userAgent()
            // ]);
            
            return response()->json([
                'status' => false,
                'message' => 'Invalid signature'
            ], 400);
        }

        $data = json_decode($payload, true);
        
        // Log::info('Paystack webhook received', [
        //     'event' => $data['event'] ?? 'unknown',
        //     'reference' => $data['data']['reference'] ?? null
        // ]);

        // Handle different webhook events
        switch ($data['event'] ?? '') {
            case 'charge.success':
                $this->handleSuccessfulPayment($data['data']);
                break;
                
            case 'charge.failed':
                $this->handleFailedPayment($data['data']);
                break;
                
            case 'charge.dispute.create':
                $this->handleDisputeCreated($data['data']);
                break;
                
            default:
                Log::info('Unhandled Paystack webhook event', [
                    'event' => $data['event'] ?? 'unknown',
                    'data' => $data['data'] ?? []
                ]);
        }

        return response()->json([
            'status' => true,
            'message' => 'Webhook processed successfully'
        ]);
    }

    /**
     * Verify Paystack webhook signature
     */
    private function verifyWebhookSignature($payload, $signature)
    {
        $secretKey = env('PAYSTACK_SECRET_KEY');
        if (empty($secretKey)) {
            return false;
        }

        $expectedSignature = hash_hmac('sha512', $payload, $secretKey);
        
        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Handle successful payment webhook
     */
    private function handleSuccessfulPayment($data)
    {
        $reference = $data['reference'] ?? null;
        if (!$reference) {
            Log::error('Paystack webhook: Missing reference in successful payment', ['data' => $data]);
            return;
        }

        // Find payment record
        $payment = Payment::where('transaction_id', $reference)
            ->orWhere('gateway_reference', $reference)
            ->first();

        if (!$payment) {
            // Log::error('Paystack webhook: Payment record not found', [
            //     'reference' => $reference,
            //     'data' => $data
            // ]);
            return;
        }

        // Update payment status
        $payment->update([
            'status' => 'completed',
            'gateway_response' => $data,
            'raw_response' => $data
        ]);

        // Create notification for payment completion
        \App\Services\PaymentService::handlePaymentCompletion($payment);

        // Update car status
        $car = \App\Models\Car::find($payment->car_id);
        if ($car) {
            $car->status = 'active';
            $car->save();

            // Create order for admin processing
            $user = \App\Models\User::find($payment->user_id);
            if ($user) {
                $this->createOrderFromPayment($payment, $car, $user);
            }

            // Handle reminders
            $this->updateCarReminders($payment, $car);
        }

        // Log::info('Paystack webhook: Payment processed successfully', [
        //     'payment_id' => $payment->id,
        //     'reference' => $reference,
        //     'amount' => $data['amount'] ?? 0
        // ]);
    }

    /**
     * Handle failed payment webhook
     */
    private function handleFailedPayment($data)
    {
        $reference = $data['reference'] ?? null;
        if (!$reference) {
            // Log::error('Paystack webhook: Missing reference in failed payment', ['data' => $data]);
            return;
        }

        // Find payment record
        $payment = Payment::where('transaction_id', $reference)
            ->orWhere('gateway_reference', $reference)
            ->first();

        if ($payment) {
            $payment->update([
                'status' => 'failed',
                'gateway_response' => $data,
                'raw_response' => $data
            ]);

            // Log::info('Paystack webhook: Payment marked as failed', [
            //     'payment_id' => $payment->id,
            //     'reference' => $reference
            // ]);
        }
    }

    /**
     * Handle dispute created webhook
     */
    private function handleDisputeCreated($data)
    {
        $reference = $data['reference'] ?? null;
        if (!$reference) {
            Log::error('Paystack webhook: Missing reference in dispute', ['data' => $data]);
            return;
        }

        // Find payment record
        $payment = Payment::where('transaction_id', $reference)
            ->orWhere('gateway_reference', $reference)
            ->first();

        if ($payment) {
            $payment->update([
                'status' => 'disputed',
                'gateway_response' => $data,
                'raw_response' => $data
            ]);

            // Log::warning('Paystack webhook: Payment disputed', [
            //     'payment_id' => $payment->id,
            //     'reference' => $reference,
            //     'dispute_reason' => $data['reason'] ?? 'Unknown'
            // ]);
        }
    }

    /**
     * Update car reminders after successful payment
     */
    private function updateCarReminders($payment, $car)
    {
        $userModel = \App\Models\User::find($payment->user_id);
        $userIdString = $userModel ? $userModel->userId : null;

        \Log::info('Updating car reminders', [
            'payment_id' => $payment->id,
            'car_id' => $car->id,
            'user_id' => $payment->user_id,
            'userIdString' => $userIdString
        ]);

        if ($userIdString) {
            // When payment is made, change status to "Processing"
            $message = 'Processing';
            $reminder = \App\Models\Reminder::updateOrCreate(
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
            
            \Log::info('Reminder updated successfully', [
                'reminder_id' => $reminder->id,
                'message' => $message,
                'user_id' => $userIdString,
                'car_id' => $car->id
            ]);
        } else {
            \Log::error('Could not update reminder - userIdString not found', [
                'payment_id' => $payment->id,
                'user_id' => $payment->user_id,
                'userModel' => $userModel
            ]);
        }
    }

    /**
     * Create order(s) from successful payment (supports bulk payments)
     */
    private function createOrderFromPayment($payment, $car, $user)
    {
        try {
            // Check if orders already exist for this payment to prevent duplicates
            $existingOrders = \App\Models\Order::where('payment_id', $payment->id)->count();
            if ($existingOrders > 0) {
                // Orders already exist for this payment, no need to create more
                return;
            }

            // Get delivery address from payment metadata or car/user
            $metaData = is_string($payment->meta_data) ? json_decode($payment->meta_data, true) : $payment->meta_data;
            $deliveryAddress = $metaData['delivery_address'] ?? $car->address ?? $user->address ?? 'Address not provided';
            $deliveryContact = $metaData['delivery_contact'] ?? $user->phone_number ?? 'Contact not provided';
            $stateId = $metaData['state_id'] ?? null;
            $lgaId = $metaData['lga_id'] ?? null;

            // Check if this is a bulk payment
            $isBulkPayment = $metaData['is_bulk_payment'] ?? false;
            $paymentSchedules = $metaData['payment_schedules'] ?? [];
            
            // Handle JSON string for payment_schedule_id
            $paymentScheduleIdData = $payment->payment_schedule_id;
            if (is_string($paymentScheduleIdData)) {
                $decodedIds = json_decode($paymentScheduleIdData, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decodedIds)) {
                    $isBulkPayment = count($decodedIds) > 1;
                    if ($isBulkPayment && empty($paymentSchedules)) {
                        // If we have multiple IDs but no schedules in meta_data, fetch them
                        $paymentSchedules = \App\Models\PaymentSchedule::with(['payment_head', 'revenue_head'])
                            ->whereIn('id', $decodedIds)
                            ->get()
                            ->map(function($schedule) {
                                return [
                                    'id' => $schedule->id,
                                    'amount' => $schedule->amount,
                                    'payment_head_name' => $schedule->payment_head->payment_head_name,
                                    'revenue_head_code' => $schedule->revenue_head->revenue_head_code,
                                ];
                            })->toArray();
                    }
                }
            }

            if ($isBulkPayment && !empty($paymentSchedules)) {
                // Create separate orders for each payment schedule in bulk payment
                foreach ($paymentSchedules as $scheduleData) {
                    $orderType = $this->getOrderTypeFromPaymentHead($scheduleData['payment_head_name']);
                    
                    \App\Models\Order::create([
                        'slug' => \Illuminate\Support\Str::uuid(),
                        'user_id' => $user->id,
                        'car_id' => $car->id,
                        'payment_id' => $payment->id,
                        'order_type' => $orderType,
                        'status' => 'pending',
                        'amount' => $scheduleData['amount'],
                        'delivery_address' => $deliveryAddress,
                        'delivery_contact' => $deliveryContact,
                        'state' => $stateId,
                        'lga' => $lgaId,
                        'notes' => "Bulk payment via {$payment->payment_gateway} - {$scheduleData['payment_head_name']} (Schedule ID: {$scheduleData['id']})",
                    ]);
                }
            } else {
                // Single payment - handle JSON array properly
                $paymentScheduleIdData = $payment->payment_schedule_id;
                
                // Handle both JSON string and array
                if (is_string($paymentScheduleIdData)) {
                    $paymentScheduleIds = json_decode($paymentScheduleIdData, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        // If not valid JSON, treat as single ID
                        $paymentScheduleIds = [$paymentScheduleIdData];
                    }
                } else {
                    $paymentScheduleIds = is_array($paymentScheduleIdData) 
                        ? $paymentScheduleIdData 
                        : [$paymentScheduleIdData];
                }
                
                if (empty($paymentScheduleIds)) {
                    Log::error('No payment schedule IDs found for single payment', [
                        'payment_id' => $payment->id,
                        'payment_schedule_id' => $payment->payment_schedule_id
                    ]);
                    return;
                }

                // Get the payment schedule
                $paymentSchedule = \App\Models\PaymentSchedule::with('payment_head')
                    ->find($paymentScheduleIds[0]);
                    
                if (!$paymentSchedule) {
                    Log::error('Payment schedule not found', [
                        'payment_id' => $payment->id,
                        'schedule_id' => $paymentScheduleIds[0]
                    ]);
                    return;
                }

                // Determine order type based on payment head
                $orderType = $this->getOrderTypeFromPaymentHead($paymentSchedule->payment_head->payment_head_name);

                // Create order
                \App\Models\Order::create([
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
            }

        } catch (\Exception $e) {
            Log::error('Failed to create order from payment', [
                'payment_id' => $payment->id,
                'gateway_reference' => $payment->gateway_reference ?? 'N/A',
                'car_id' => $payment->car_id,
                'user_id' => $payment->user_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Re-throw the exception so the calling code knows it failed
            throw $e;
        }
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
            
            // Handle array payment_schedule_id (for bulk payments or empty arrays)
            if (is_array($paymentScheduleId)) {
                if (count($paymentScheduleId) === 0) {
                    // For driver license payments with empty array, skip payment schedule lookup
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
        
        if ($isRenewal) {
            // Create car renewal notification
            NotificationService::notifyCarOperation($userId, 'renewed', $car, "Payment of ₦" . number_format($payment->amount, 2) . " completed successfully.");
        } else {
            // Create generic payment notification
            NotificationService::notifyPaymentOperation($userId, 'completed', $payment);
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
            $licenseYear = $metaData['license_year'] ?? null;
            $baseAmount = $metaData['base_amount'] ?? null;
            $expectedAmount = $baseAmount * $licenseYear;
            
            if ($payment->amount !== $expectedAmount) {
                \Log::error('Payment amount tampering detected in Paystack payment', [
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

                \Log::info('Driver license payment processed successfully via Paystack', [
                    'payment_id' => $payment->id,
                    'driver_license_id' => $driverLicenseId,
                    'license_slug' => $driverLicense->slug,
                    'user_id' => $user->id,
                    'payment_gateway' => 'paystack'
                ]);
            } else {
                \Log::error('Driver license not found for payment', [
                    'payment_id' => $payment->id,
                    'driver_license_id' => $driverLicenseId
                ]);
            }
        } catch (\Exception $e) {
            \Log::error('Failed to handle driver license payment success via Paystack', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Format payment for response
     */
    private function formatPayment($payment)
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
