<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\PaymentSchedule;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PaystackPaymentController extends Controller
{
    /**
     * Initialize Paystack payment for car renewal
     */
    public function initializePayment(Request $request)
    {
        $user = Auth::user();
        $transaction_id = Str::random(10);

        // Validation: car_slug is required
        $request->validate([
            'car_slug' => 'required|exists:cars,slug',
            'payment_schedule_id' => 'required|exists:payment_schedules,id',
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

        $getPaymentSchedule = PaymentSchedule::with(['payment_head', 'revenue_head'])->find($request->payment_schedule_id);
        if (!$getPaymentSchedule) {
            return response()->json(['message' => 'Invalid payment_schedule_id'], 400);
        }
        if (!$getPaymentSchedule->payment_head || !$getPaymentSchedule->revenue_head) {
            return response()->json(['message' => 'Payment schedule is missing payment head or revenue head'], 400);
        }

        // --- Car type/payment schedule validation ---
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

        // Create payment record
        $payment = Payment::create([
            'user_id' => $user->id,
            'car_id' => $car->id,
            'payment_schedule_id' => $request->payment_schedule_id,
            'amount' => $totalAmount,
            'transaction_id' => $transaction_id,
            'status' => 'pending',
            'payment_gateway' => 'paystack',
            'meta_data' => json_encode(array_merge($metaData, [
                'delivery_fee' => $deliveryFee,
                'base_amount' => $baseAmount,
                'car_slug' => $car->slug,
                'payment_head_name' => $getPaymentSchedule->payment_head->payment_head_name,
                'revenue_head_code' => $getPaymentSchedule->revenue_head->revenue_head_code,
            ])),
        ]);

        // Prepare Paystack payload
        $payload = [
            'email' => $user->email,
            'amount' => $totalAmount * 100, // Paystack expects amount in kobo
            'reference' => $transaction_id,
            'currency' => 'NGN',
            'callback_url' => env('APP_URL') . '/api/payment/paystack/callback',
            'metadata' => [
                'user_id' => $user->id,
                'car_id' => $car->id,
                'car_slug' => $car->slug,
                'payment_schedule_id' => $request->payment_schedule_id,
                'delivery_fee' => $deliveryFee,
                'base_amount' => $baseAmount,
                'payment_head_name' => $getPaymentSchedule->payment_head->payment_head_name,
                'revenue_head_code' => $getPaymentSchedule->revenue_head->revenue_head_code,
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
                // Update car status
                $car = \App\Models\Car::find($payment->car_id);
                if ($car) {
                    $car->status = 'active';
                    $car->save();

                    // Create order for admin processing
                    $this->createOrderFromPayment($payment, $car, $user);

                    // Handle reminders (same logic as Monicredit)
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
                            $message = 'License expires today.';
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
                    'status' => true,
                    'message' => 'Payment verified successfully',
                    'data' => $data,
                    'payment_date' => $payment->created_at,
                    'car' => $car,
                    // 'payment' => $this->formatPayment($payment)
                ]);
            }

            // Payment not successful
            return response()->json([
                'status' => false,
                'message' => 'Payment not successful',
                'data' => $data
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
                $message = 'License expires today.';
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

    /**
     * Create order from successful payment
     */
    private function createOrderFromPayment($payment, $car, $user)
    {
        try {
            // Get payment schedule details
            $paymentSchedule = $payment->paymentSchedule;
            if (!$paymentSchedule) {
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

        } catch (\Exception $e) {
            Log::error('Failed to create order from payment', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get order type from payment head name
     */
    private function getOrderTypeFromPaymentHead($paymentHeadName)
    {
        $paymentHeadName = strtolower($paymentHeadName);
        
        if (strpos($paymentHeadName, 'license') !== false || strpos($paymentHeadName, 'renewal') !== false) {
            return 'license_renewal';
        } elseif (strpos($paymentHeadName, 'registration') !== false) {
            return 'vehicle_registration';
        } elseif (strpos($paymentHeadName, 'insurance') !== false) {
            return 'insurance';
        } elseif (strpos($paymentHeadName, 'inspection') !== false) {
            return 'inspection';
        } else {
            return 'general_service';
        }
    }

    /**
     * Format payment for response
     */
    private function formatPayment($payment)
    {
        return [
            'id' => $payment->id,
            'slug' => $payment->slug,
            'amount' => $payment->amount,
            'status' => $payment->status,
            'payment_gateway' => $payment->payment_gateway,
            'transaction_id' => $payment->transaction_id,
            'created_at' => $payment->created_at,
            'payment_schedule' => $payment->paymentSchedule,
            'car' => $payment->car,
        ];
    }
}
