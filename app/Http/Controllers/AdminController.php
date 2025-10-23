<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Payment;
use App\Models\Car;
use App\Models\Agent;
use App\Models\Order;
use App\Models\AgentPayment;
use App\Models\Reminder;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class AdminController extends Controller
{
    /**
     * Send OTP to admin email
     */
    public function sendAdminOTP(Request $request)
    {
        $request->validate([
            'email' => 'required|email'
        ]);

        $email = $request->email;
        
        // Check if email is authorized admin
        $admin = User::where('email', $email)
            ->where('is_admin', true)
            ->first();

        if (!$admin) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized admin email'
            ], 403);
        }

        // Rate limiting
        $key = 'admin_otp:' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, 3)) {
            $seconds = RateLimiter::availableIn($key);
            return response()->json([
                'status' => false,
                'message' => "Too many attempts. Please try again in {$seconds} seconds."
            ], 429);
        }

        RateLimiter::hit($key, 900); // 15 minutes

        // Generate OTP
        $otp = $this->generateSecureOTP();
        
        // Store OTP in cache for 10 minutes
        Cache::put("admin_otp:{$email}", $otp, 600);

        // Send OTP via email
        try {
            Mail::raw("Your admin login OTP is: {$otp}\n\nThis OTP will expire in 10 minutes.", function ($message) use ($email) {
                $message->to($email)
                    ->subject('Admin Login OTP - Motoka');
            });

            return response()->json([
                'status' => true,
                'message' => 'OTP sent successfully to your email'
            ]);
        } catch (\Exception $e) {
            Log::error('Admin OTP email failed', [
                'email' => $email,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Failed to send OTP. Please try again.'
            ], 500);
        }
    }

    /**
     * Verify admin OTP and login
     */
    public function verifyAdminOTP(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'otp' => 'required|string|size:4'
        ]);

        $email = $request->email;
        $otp = $request->otp;

        // Rate limiting per email (not per IP) to avoid cross-session interference
        $key = 'admin_otp_verify:' . $email;
        if (RateLimiter::tooManyAttempts($key, 3)) {
            $seconds = RateLimiter::availableIn($key);
            return response()->json([
                'status' => false,
                'message' => "Too many attempts for this email. Please try again in {$seconds} seconds."
            ], 429);
        }

        // Get stored OTP
        $storedOTP = Cache::get("admin_otp:{$email}");
        
        if (!$storedOTP || $storedOTP !== $otp) {
            // Only hit rate limiter on invalid OTP attempts
            RateLimiter::hit($key, 300); // 5 minutes for invalid attempts
            
            return response()->json([
                'status' => false,
                'message' => 'Invalid or expired OTP'
            ], 400);
        }

        // Get admin user
        $admin = User::where('email', $email)
            ->where('is_admin', true)
            ->first();

        if (!$admin) {
            return response()->json([
                'status' => false,
                'message' => 'Admin not found'
            ], 404);
        }

        // Clear OTP from cache and rate limiter
        Cache::forget("admin_otp:{$email}");
        RateLimiter::clear($key); // Clear rate limiter on successful verification

        // Create token
        $token = $admin->createToken('admin-token')->plainTextToken;

        return response()->json([
            'status' => true,
            'message' => 'Admin login successful',
            'data' => [
                'admin' => [
                    'id' => $admin->id,
                    'name' => $admin->name,
                    'email' => $admin->email,
                    'user_type' => $admin->user_type,
                ],
                'token' => $token
            ]
        ]);
    }

    /**
     * Get admin dashboard stats
     */
    public function getDashboardStats()
    {
        $admin = Auth::user();

        if (!$admin->is_admin) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized access'
            ], 403);
        }

        $stats = [
            'total_orders' => Order::count(),
            'pending_orders' => Order::where('status', 'pending')->count(),
            'in_progress_orders' => Order::where('status', 'in_progress')->count(),
            'completed_orders' => Order::where('status', 'completed')->count(),
            'declined_orders' => Order::where('status', 'declined')->count(),
            'total_agents' => Agent::count(),
            'active_agents' => Agent::where('status', 'active')->count(),
            'total_cars' => Car::count(),
            'total_amount' => Order::sum('amount'),
            'completed_amount' => Order::where('status', 'completed')->sum('amount'),
            'pending_amount' => Order::where('status', 'pending')->sum('amount'),
            'declined_amount' => Order::where('status', 'declined')->sum('amount'),
        ];

        return response()->json([
            'status' => true,
            'data' => $stats
        ]);
    }

    /**
     * Get orders with pagination
     */
    public function getOrders(Request $request)
    {
        $admin = Auth::user();

        if (!$admin->is_admin) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized access'
            ], 403);
        }

        $perPage = $request->get('per_page', 15);
        $status = $request->get('status', 'all');

        // Map frontend status to database status
        $statusMap = [
            'all' => null,
            'new' => 'pending',
            'in_progress' => 'in_progress',
            'completed' => 'completed',
            'declined' => 'declined'
        ];

        $query = Order::with(['user', 'car', 'driverLicense', 'payment', 'agent'])
            ->leftJoin('states', 'orders.state', '=', 'states.id')
            ->leftJoin('lgas', 'orders.lga', '=', 'lgas.id')
            ->select('orders.*', 'states.state_name as state_name', 'lgas.lga_name as lga_name')
            ->orderBy('orders.created_at', 'desc');

        if ($status !== 'all' && isset($statusMap[$status])) {
            $query->where('status', $statusMap[$status]);
        }

        $orders = $query->paginate($perPage);

        // Enhance orders data with driver license information
        $orders->getCollection()->transform(function ($order) {
            $orderData = $order->toArray();
            
            // Add driver license summary for list view
            if ($order->driver_license_id && $order->driverLicense) {
                $paymentMetaData = $order->payment->meta_data ?? [];
                
                $orderData['driver_license_summary'] = [
                    'license_type' => $order->driverLicense->license_type,
                    'license_year' => $order->driverLicense->license_year,
                    'full_name' => $order->driverLicense->full_name,
                    'phone_number' => $order->driverLicense->phone_number,
                    'base_amount' => $paymentMetaData['base_amount'] ?? null,
                    'total_amount' => $paymentMetaData['total_amount'] ?? null,
                    'calculation' => ($paymentMetaData['base_amount'] ?? 0) . ' × ' . ($paymentMetaData['license_year'] ?? 0) . ' = ' . ($paymentMetaData['total_amount'] ?? 0)
                ];
            }
            
            return $orderData;
        });

        return response()->json([
            'status' => true,
            'data' => $orders
        ]);
    }

    /**
     * Get single order by slug
     */
    public function getOrder($slug)
    {
        $admin = Auth::user();

        if (!$admin->is_admin) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized access'
            ], 403);
        }

        $order = Order::with(['user', 'car', 'driverLicense', 'payment', 'agent'])
            ->leftJoin('states', 'orders.state', '=', 'states.id')
            ->leftJoin('lgas', 'orders.lga', '=', 'lgas.id')
            ->select('orders.*', 'states.state_name as state_name', 'lgas.lga_name as lga_name')
            ->where('orders.slug', $slug)
            ->first();

        if (!$order) {
            return response()->json([
                'status' => false,
                'message' => 'Order not found'
            ], 404);
        }

        // Enhance order data with driver license specific information
        $orderData = $order->toArray();
        
        // Add driver license specific details if this is a driver license order
        if ($order->driver_license_id && $order->driverLicense) {
            $driverLicense = $order->driverLicense;
            $paymentMetaData = $order->payment->meta_data ?? [];
            
            $orderData['driver_license_details'] = [
                'license_type' => $driverLicense->license_type,
                'license_year' => $driverLicense->license_year,
                'full_name' => $driverLicense->full_name,
                'phone_number' => $driverLicense->phone_number,
                'address' => $driverLicense->address,
                'date_of_birth' => $driverLicense->date_of_birth,
                'place_of_birth' => $driverLicense->place_of_birth,
                'state_of_origin' => $driverLicense->state_of_origin,
                'local_government' => $driverLicense->local_government,
                'blood_group' => $driverLicense->blood_group,
                'height' => $driverLicense->height,
                'occupation' => $driverLicense->occupation,
                'next_of_kin' => $driverLicense->next_of_kin,
                'next_of_kin_phone' => $driverLicense->next_of_kin_phone,
                'mother_maiden_name' => $driverLicense->mother_maiden_name,
                'passport_photograph' => $driverLicense->passport_photograph,
                'license_number' => $driverLicense->license_number,
                'status' => $driverLicense->status,
            ];
            
            // Add payment calculation details
            $orderData['payment_calculation'] = [
                'base_amount' => $paymentMetaData['base_amount'] ?? null,
                'license_year' => $paymentMetaData['license_year'] ?? null,
                'total_amount' => $paymentMetaData['total_amount'] ?? null,
                'calculation_formula' => 'Base Amount × License Years = Total Amount',
                'calculation_breakdown' => ($paymentMetaData['base_amount'] ?? 0) . ' × ' . ($paymentMetaData['license_year'] ?? 0) . ' = ' . ($paymentMetaData['total_amount'] ?? 0)
            ];
            
            // Add revenue head information
            $orderData['revenue_head'] = [
                'code' => $paymentMetaData['revenue_head_code'] ?? null,
                'description' => $order->payment->payment_description ?? null
            ];
        }

        return response()->json([
            'status' => true,
            'data' => $orderData
        ]);
    }

    /**
     * Process order - assign to agent and initiate payment
     */
    public function processOrder(Request $request, $slug)
    {
        $admin = Auth::user();

        if (!$admin->is_admin) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized access'
            ], 403);
        }

        $request->validate([
            'commission_rate' => 'nullable|numeric|min:0|max:100',
            'initiate_payment' => 'nullable|boolean'
        ]);

        $order = Order::where('slug', $slug)->first();
        if (!$order) {
            return response()->json([
                'status' => false,
                'message' => 'Order not found'
            ], 404);
        }

        // Resolve state ID to state name
        $state = \App\Models\State::find($order->state);
        if (!$state) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid state ID: ' . $order->state
            ], 400);
        }

        // Find agent for the order's state
        $agent = Agent::where('state', $state->state_name)
            ->where('status', 'active')
            ->first();

        if (!$agent) {
            return response()->json([
                'status' => false,
                'message' => 'No active agent found for state: ' . $state->state_name
            ], 404);
        }

        // Check if agent has bank details
        if (empty($agent->account_number) || empty($agent->bank_name)) {
            return response()->json([
                'status' => false,
                'message' => 'Agent bank details are incomplete. Please update agent information.',
                'agent' => $agent
            ], 400);
        }

        // Assign agent to order
        $order->update([
            'agent_id' => $agent->id,
            'status' => 'in_progress',
            'processed_at' => now(),
            'processed_by' => $admin->id,
        ]);

        $responseData = [
            'order' => $order->load('agent'),
            'agent' => $agent,
            'payment_required' => true,
            'payment_details' => [
                'order_amount' => $order->amount,
                'commission_rate' => $request->commission_rate ?? 10,
                'commission_amount' => ($order->amount * ($request->commission_rate ?? 10)) / 100,
                'agent_amount' => $order->amount - (($order->amount * ($request->commission_rate ?? 10)) / 100),
                'agent_bank_details' => [
                    'bank_name' => $agent->bank_name,
                    'account_number' => $agent->account_number,
                    'account_name' => $agent->account_name
                ]
            ]
        ];

        // Initiate payment if requested
        if ($request->initiate_payment) {
            $commissionRate = $request->commission_rate ?? 10;
            $transferResult = \App\Services\PaystackTransferService::initiateTransfer(
                $order, 
                $agent, 
                $order->amount, 
                $commissionRate
            );

            if ($transferResult['success']) {
                $responseData['payment_initiated'] = true;
                $responseData['transfer_reference'] = $transferResult['transfer_reference'];
                $responseData['payment_details']['transfer_reference'] = $transferResult['transfer_reference'];
                $responseData['payment_details']['agent_payment_id'] = $transferResult['agent_payment_id'];
                
                // Send notification to agent about payment
                $this->notifyAgentPayment($order, $agent, $transferResult);
            } else if (isset($transferResult['manual_payment_required']) && $transferResult['manual_payment_required']) {
                // Handle manual payment scenario
                $responseData['manual_payment_required'] = true;
                $responseData['transfer_reference'] = $transferResult['transfer_reference'];
                $responseData['payment_details']['transfer_reference'] = $transferResult['transfer_reference'];
                $responseData['payment_details']['agent_payment_id'] = $transferResult['agent_payment_id'];
                $responseData['agent_details'] = $transferResult['agent_details'];
                
                // Send notification to agent about manual payment
                $this->notifyAgentManualPayment($order, $agent, $transferResult);
            } else {
                return response()->json([
                    'status' => false,
                    'message' => 'Failed to initiate payment: ' . $transferResult['message'],
                    'data' => $responseData
                ], 500);
            }
        }

        // Only send notification to agent if payment was initiated
        if ($request->initiate_payment) {
            // Send notification to agent (WhatsApp and Email) only after payment
            $paymentDetails = null;
            if (isset($responseData['payment_details'])) {
                $paymentDetails = [
                    'transfer_reference' => $responseData['payment_details']['transfer_reference'] ?? null,
                    'amount' => $responseData['payment_details']['agent_amount'] ?? 0,
                    'commission_amount' => $responseData['payment_details']['commission_amount'] ?? 0,
                    'status' => $responseData['manual_payment_required'] ? 'Manual Payment Required' : 'Completed'
                ];
            }
            $this->notifyAgent($order, $agent, $paymentDetails);
        }

        return response()->json([
            'status' => true,
            'message' => $request->initiate_payment ? 
                'Order processed and payment initiated successfully. Agent has been notified.' : 
                'Order assigned to agent successfully. Payment can be initiated.',
            'data' => $responseData
        ]);
    }

    /**
     * Update order status
     */
    public function updateOrderStatus(Request $request, $slug)
    {
        $admin = Auth::user();

        if (!$admin->is_admin) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized access'
            ], 403);
        }

        $request->validate([
            'status' => 'required|in:pending,in_progress,completed,declined'
        ]);

        $order = Order::where('slug', $slug)->first();
        if (!$order) {
            return response()->json([
                'status' => false,
                'message' => 'Order not found'
            ], 404);
        }

        $updateData = ['status' => $request->status];
        
        if ($request->status === 'completed') {
            // Check if documents have been sent to the user
            if (!$order->documents_sent_at) {
                return response()->json([
                    'status' => false,
                    'message' => 'Cannot complete order. Documents must be sent to the user first.'
                ], 400);
            }
            
            $updateData['completed_at'] = now();
            
            // Update agent payment when order is completed
            if ($order->agent_id) {
                $this->updateAgentPayment($order);
            }
        }

        $order->update($updateData);

        return response()->json([
            'status' => true,
            'message' => 'Order status updated successfully',
            'data' => $order
        ]);
    }

    /**
     * Get agents with pagination
     */
    public function getAgents(Request $request)
    {
        $admin = Auth::user();

        if (!$admin->is_admin) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized access'
            ], 403);
        }

        $perPage = $request->get('per_page', 15);
        $status = $request->get('status', 'all');

        $query = Agent::orderBy('created_at', 'desc');

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $agents = $query->paginate($perPage);

        return response()->json([
            'status' => true,
            'data' => $agents
        ]);
    }

    /**
     * Get single agent by slug
     */
    public function getAgent($slug)
    {
        $admin = Auth::user();

        if (!$admin->is_admin) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized access'
            ], 403);
        }

        $agent = Agent::where('slug', $slug)->first();

        if (!$agent) {
            return response()->json([
                'status' => false,
                'message' => 'Agent not found'
            ], 404);
        }

        return response()->json([
            'status' => true,
            'data' => $agent
        ]);
    }

    /**
     * Get cars with pagination
     */
    public function getCars(Request $request)
    {
        $admin = Auth::user();

        if (!$admin->is_admin) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized access'
            ], 403);
        }



        $perPage = $request->input('per_page', 15);
        $status = $request->input('status', 'all');
        $search = $request->input('search', '');

        $query = Car::with(['user:id,userId,name,email'])
            ->orderBy('created_at', 'desc');

       
        if ($status !== 'all') {
            $query->where('status', $status);
        }

        
        if (!empty($search)) {
            $query->where(function($q) use ($search) {
                $q->where('vehicle_make', 'like', "%{$search}%")
                  ->orWhere('vehicle_model', 'like', "%{$search}%")
                  ->orWhere('registration_no', 'like', "%{$search}%")
                  ->orWhere('name_of_owner', 'like', "%{$search}%");
            });
        }

        $cars = $query->paginate($perPage);

        return response()->json([
            'status' => true,
            'message' => 'Cars retrieved successfully',
            'data' => $cars
        ]);

        // $perPage = $request->get('per_page', 15);

        // $cars = Car::with('user')
        //     ->orderBy('created_at', 'desc')
        //     ->paginate($perPage);

        // return response()->json([
        //     'status' => true,
        //     'data' => $cars
        // ]);
    }

    /**
     * Get single car by slug
     */
    public function getCar($slug)
    {
        $admin = Auth::user();

        if (!$admin->is_admin) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized access'
            ], 403);
        }



        $car = Car::where('slug', $slug)
            ->with([
                'user:id,userId,name,email,phone',
                'orders.orderDocuments' => function($query) {
                    $query->where('status', 'approved');
                },
                'orders.agent:id,uuid,first_name,last_name,email,phone',
                'orders.stateInfo:id,state_name',
                'orders.lgaInfo:id,lga_name,state_id',
                'orders.processedBy:id,userId,name,email'
            ])
            ->first();

        if (!$car) {
            return response()->json([
                'status' => false,
                'message' => 'Car not found'
            ], 404);
        }

        return response()->json([
            'status' => true,
            'message' => 'Car retrieved successfully',
            'data' => $car
        ]);





        // $car = Car::with('user')->where('slug', $slug)->first();

        // if (!$car) {
        //     return response()->json([
        //         'status' => false,
        //         'message' => 'Car not found'
        //     ], 404);
        // }

        // return response()->json([
        //     'status' => true,
        //     'data' => $car
        // ]);
    }



    /**
     * Delete a car (Admin only)
     */
    public function deleteCar($slug)
    {
        $admin = Auth::user();

        if (!$admin->is_admin) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized access'
            ], 403);
        }


        $car = Car::where('slug', $slug)->first();

        if (!$car) {
            return response()->json([
                'status' => false,
                'message' => 'Car not found'
            ], 404);
        }

        // Store car info before deletion
        $carInfo = $car->toArray();
        $userId = $car->user_id;

        // Delete associated document images
        if (!empty($car->document_images)) {
            foreach ($car->document_images as $path) {
                if (file_exists(public_path($path))) {
                    unlink(public_path($path));
                }
            }
        }

        // Delete plate-related documents
        $plateDocuments = ['cac_document', 'letterhead', 'means_of_identification'];
        foreach ($plateDocuments as $docField) {
            if (!empty($car->$docField) && file_exists(public_path($car->$docField))) {
                unlink(public_path($car->$docField));
            }
        }

        // Delete associated reminders
        Reminder::where('user_id', $userId)
            ->where('ref_id', $car->id)
            ->where('type', 'car')
            ->delete();

        // Delete the car
        $car->delete();

        // Send notification to user
        NotificationService::notifyCarOperation($userId, 'deleted', (object)$carInfo);

        return response()->json([
            'status' => true,
            'message' => 'Car and associated data deleted successfully'
        ]);
    }




    /**
     * Notify agent about new order
     */
    public function notifyAgent($order, $agent, $paymentDetails = null)
    {
        try {
            // Get state and LGA names
            $state = \App\Models\State::find($order->state);
            $lga = \App\Models\Lga::find($order->lga);
            
            $stateName = $state ? $state->state_name : 'Unknown State';
            $lgaName = $lga ? $lga->lga_name : 'Unknown LGA';
            
            // WhatsApp notification
            if ($paymentDetails) {
                // Include payment receipt information
                $whatsappMessage = "🎉 *Order Assigned with Payment Receipt!*\n\n";
                $whatsappMessage .= "📋 *Order Details:*\n";
                $whatsappMessage .= "Order ID: #{$order->slug}\n";
                $whatsappMessage .= "Service: " . ucwords(str_replace('_', ' ', $order->order_type)) . "\n";
                $whatsappMessage .= "Customer: {$order->user->firstName} {$order->user->lastName}\n";
                $whatsappMessage .= "Phone: {$order->user->phone}\n";
                $whatsappMessage .= "Car: {$order->car->vehicle_make} {$order->car->vehicle_model}\n";
                $whatsappMessage .= "Address: {$order->delivery_address}\n";
                $whatsappMessage .= "Contact: {$order->delivery_contact}\n";
                $whatsappMessage .= "Location: {$stateName}, {$lgaName}\n\n";
                
                $whatsappMessage .= "💰 *Payment Receipt:*\n";
                $whatsappMessage .= "Transfer Ref: {$paymentDetails['transfer_reference']}\n";
                $whatsappMessage .= "Amount Paid: ₦" . number_format($paymentDetails['amount'], 2) . "\n";
                $whatsappMessage .= "Status: " . ($paymentDetails['status'] ?? 'Completed') . "\n";
                $whatsappMessage .= "Paid At: " . now()->format('Y-m-d H:i:s') . "\n\n";
                
                $whatsappMessage .= "✅ *Payment confirmed! Please process this order and return to admin.*";
            } else {
                // Regular order notification (without payment)
                $whatsappMessage = "📋 *New Order Assigned!*\n\n";
                $whatsappMessage .= "Order ID: #{$order->slug}\n";
                $whatsappMessage .= "Service: " . ucwords(str_replace('_', ' ', $order->order_type)) . "\n";
                $whatsappMessage .= "Customer: {$order->user->firstName} {$order->user->lastName}\n";
                $whatsappMessage .= "Phone: {$order->user->phone}\n";
                $whatsappMessage .= "Car: {$order->car->vehicle_make} {$order->car->vehicle_model}\n";
                $whatsappMessage .= "Amount: ₦{$order->amount}\n";
                $whatsappMessage .= "Address: {$order->delivery_address}\n";
                $whatsappMessage .= "Contact: {$order->delivery_contact}\n";
                $whatsappMessage .= "Location: {$stateName}, {$lgaName}\n\n";
                $whatsappMessage .= "⏳ *Payment pending. You will receive payment details once payment is processed.*";
            }

            // Email notification
            $emailData = [
                'agent' => $agent,
                'order' => $order,
                'payment_details' => $paymentDetails,
                'whatsapp_message' => $whatsappMessage,
                'stateName' => $stateName,
                'lgaName' => $lgaName
            ];

            $subject = $paymentDetails ? 
                "Order Assigned with Payment Receipt - {$order->slug}" : 
                "New Order Assigned - {$order->slug}";

            Mail::send('emails.agent-order-notification', $emailData, function ($message) use ($agent, $order, $subject) {
                $message->to($agent->email)->subject($subject);
            });

            Log::info('Agent notified about order', [
                'agent_id' => $agent->id,
                'order_slug' => $order->slug,
                'has_payment' => $paymentDetails ? true : false,
                'whatsapp_message' => $whatsappMessage
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to notify agent', [
                'agent_id' => $agent->id,
                'order_slug' => $order->slug,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Generate secure OTP
     */
    private function generateSecureOTP()
    {
        $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $otp = '';
        
        for ($i = 0; $i < 4; $i++) {
            $otp .= $characters[rand(0, strlen($characters) - 1)];
        }
        
        return $otp;
    }

    /**
     * Clear rate limiters for testing (remove in production)
     */
    public function clearRateLimiters(Request $request)
    {
        if (config('app.env') !== 'local') {
            return response()->json(['message' => 'Not available in production'], 403);
        }

        $email = $request->get('email');
        if ($email) {
            RateLimiter::clear('admin_otp:' . $request->ip());
            RateLimiter::clear('admin_otp_verify:' . $email);
            Cache::forget("admin_otp:{$email}");
        }

        return response()->json(['message' => 'Rate limiters cleared']);
    }

    /**
     * Get all states for dropdown
     */
    public function getStates()
    {
        $states = \App\Models\State::select('id', 'state_name as name')
            ->distinct()
            ->orderBy('state_name')
            ->get();

        return response()->json([
            'status' => true,
            'data' => $states
        ]);
    }

    /**
     * Check if agent exists for a state
     */
    public function checkAgentState(Request $request)
    {
        $admin = Auth::user();

        if (!$admin->is_admin) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized access'
            ], 403);
        }

        $request->validate([
            'state' => 'required|string'
        ]);

        $existingAgent = \App\Models\Agent::where('state', $request->state)
            ->where('status', '!=', 'deleted')
            ->first();

        if ($existingAgent) {
            return response()->json([
                'status' => false,
                'message' => "An agent already exists for {$request->state} state. Only one agent is allowed per state.",
                'existing_agent' => [
                    'name' => $existingAgent->first_name . ' ' . $existingAgent->last_name,
                    'email' => $existingAgent->email,
                    'state' => $existingAgent->state
                ]
            ], 422);
        }

        return response()->json([
            'status' => true,
            'message' => 'State is available for new agent'
        ]);
    }

    /**
     * Create a new agent
     */
    public function createAgent(Request $request)
    {
        $admin = Auth::user();

        if (!$admin->is_admin) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized access'
            ], 403);
        }

        $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|email|unique:agents,email',
            'phone' => 'nullable|string|max:20',
            'address' => 'required|string',
            'state' => 'required|string',
            'lga' => 'required|string',
            'account_number' => 'nullable|string|max:20',
            'bank_name' => 'nullable|string|max:255',
            'bank_code' => 'nullable|string|max:10',
            'account_name' => 'nullable|string|max:255',
            'profile_image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'nin_front_image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'nin_back_image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'notes' => 'nullable|string',
        ]);

        // Check if an agent already exists for this state
        $existingAgent = \App\Models\Agent::where('state', $request->state)
            ->where('status', '!=', 'deleted')
            ->first();

        if ($existingAgent) {
            return response()->json([
                'status' => false,
                'message' => "An agent already exists for {$request->state} state. Only one agent is allowed per state.",
                'existing_agent' => [
                    'name' => $existingAgent->first_name . ' ' . $existingAgent->last_name,
                    'email' => $existingAgent->email,
                    'state' => $existingAgent->state
                ]
            ], 422);
        }

        try {
            $agentData = $request->only([
                'first_name', 'last_name', 'email', 'phone', 'address',
                'state', 'lga', 'account_number', 'bank_name', 'bank_code', 'account_name',
                'notes'
            ]);

            // Handle file uploads
            if ($request->hasFile('profile_image')) {
                $profileImage = $request->file('profile_image');
                $profileImageName = time() . '_profile_' . $profileImage->getClientOriginalName();
                
                // Create directory if it doesn't exist
                $directory = public_path('images/agents');
                if (!file_exists($directory)) {
                    mkdir($directory, 0755, true);
                }
                
                // Move file to public/images/agents directory
                $profileImage->move($directory, $profileImageName);
                $agentData['profile_image'] = 'images/agents/' . $profileImageName;
            }

            if ($request->hasFile('nin_front_image')) {
                $ninFrontImage = $request->file('nin_front_image');
                $ninFrontImageName = time() . '_nin_front_' . $ninFrontImage->getClientOriginalName();
                
                // Create directory if it doesn't exist
                $directory = public_path('images/agents');
                if (!file_exists($directory)) {
                    mkdir($directory, 0755, true);
                }
                
                // Move file to public/images/agents directory
                $ninFrontImage->move($directory, $ninFrontImageName);
                $agentData['nin_front_image'] = 'images/agents/' . $ninFrontImageName;
            }

            if ($request->hasFile('nin_back_image')) {
                $ninBackImage = $request->file('nin_back_image');
                $ninBackImageName = time() . '_nin_back_' . $ninBackImage->getClientOriginalName();
                
                // Create directory if it doesn't exist
                $directory = public_path('images/agents');
                if (!file_exists($directory)) {
                    mkdir($directory, 0755, true);
                }
                
                // Move file to public/images/agents directory
                $ninBackImage->move($directory, $ninBackImageName);
                $agentData['nin_back_image'] = 'images/agents/' . $ninBackImageName;
            }

            $agent = \App\Models\Agent::create($agentData);

            return response()->json([
                'status' => true,
                'message' => 'Agent created successfully',
                'data' => $agent
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            $errors = $e->errors();
            $errorMessages = [];
            
            // Convert validation errors to user-friendly messages
            foreach ($errors as $field => $messages) {
                foreach ($messages as $message) {
                    if (str_contains($message, 'email') && str_contains($message, 'unique')) {
                        $errorMessages[] = 'This email address is already registered. Please use a different email.';
                    } elseif (str_contains($message, 'phone') && str_contains($message, 'unique')) {
                        $errorMessages[] = 'This phone number is already registered. Please use a different phone number.';
                    } elseif (str_contains($message, 'required')) {
                        $fieldName = str_replace('_', ' ', $field);
                        $errorMessages[] = ucfirst($fieldName) . ' is required.';
                    } elseif (str_contains($message, 'email')) {
                        $errorMessages[] = 'Please enter a valid email address.';
                    } elseif (str_contains($message, 'max:')) {
                        $errorMessages[] = ucfirst(str_replace('_', ' ', $field)) . ' is too long.';
                    } else {
                        $errorMessages[] = $message;
                    }
                }
            }
            
            return response()->json([
                'status' => false,
                'message' => implode(' ', $errorMessages),
                'errors' => $errors
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Agent creation failed: ' . $e->getMessage(), [
                'request_data' => $request->except(['profile_image', 'nin_front_image', 'nin_back_image']),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Provide specific error messages based on the exception
            if (str_contains($e->getMessage(), 'email')) {
                $message = 'Email address is already in use. Please use a different email.';
            } elseif (str_contains($e->getMessage(), 'phone')) {
                $message = 'Phone number is already in use. Please use a different phone number.';
            } elseif (str_contains($e->getMessage(), 'file')) {
                $message = 'File upload failed. Please check your file and try again.';
            } else {
                $message = 'Failed to create agent. Please check your information and try again.';
            }
            
            return response()->json([
                'status' => false,
                'message' => $message
            ], 500);
        }
    }

    /**
     * Get agent by UUID with pagination
     */
    public function getAgentByUuid($uuid)
    {
        try {
            $agent = \App\Models\Agent::where('uuid', $uuid)->first();
            
            if (!$agent) {
                return response()->json([
                    'status' => false,
                    'message' => 'Agent not found'
                ], 404);
            }

            // Get agent payments with pagination
            $payments = $agent->agentPayments()
                ->with('order')
                ->orderBy('created_at', 'desc')
                ->paginate(10);

            // Get agent orders with pagination
            $orders = $agent->orders()
                ->orderBy('created_at', 'desc')
                ->paginate(10);

            return response()->json([
                'status' => true,
                'data' => [
                    'agent' => $agent,
                    'payments' => $payments,
                    'orders' => $orders
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Get agent by UUID failed: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to fetch agent details'
            ], 500);
        }
    }

    /**
     * Update agent status (suspend/activate/delete)
     */
    public function updateAgentStatus(Request $request, $uuid)
    {
        try {
            $agent = \App\Models\Agent::where('uuid', $uuid)->first();
            
            if (!$agent) {
                return response()->json([
                    'status' => false,
                    'message' => 'Agent not found'
                ], 404);
            }

            $request->validate([
                'status' => 'required|in:active,suspended,deleted'
            ]);

            $agent->status = $request->status;
            $agent->save();

            $statusMessages = [
                'active' => 'Agent activated successfully',
                'suspended' => 'Agent suspended successfully',
                'deleted' => 'Agent deleted successfully'
            ];

            return response()->json([
                'status' => true,
                'message' => $statusMessages[$request->status],
                'data' => $agent
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid status provided'
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Update agent status failed: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to update agent status'
            ], 500);
        }
    }

    /**
     * Update agent details
     */
    public function updateAgent(Request $request, $uuid)
    {
        try {
            $agent = \App\Models\Agent::where('uuid', $uuid)->first();
            
            if (!$agent) {
                return response()->json([
                    'status' => false,
                    'message' => 'Agent not found'
                ], 404);
            }

            // Debug: Log received data
            // \Log::info('Agent update request received', [
            //     'uuid' => $uuid,
            //     'received_data' => $request->all(),
            //     'agent_id' => $agent->id
            // ]);

            $request->validate([
                'first_name' => 'required|string|max:255',
                'last_name' => 'required|string|max:255',
                'email' => 'required|email|unique:agents,email,' . $agent->id,
                'phone' => 'nullable|string|max:20',
                'address' => 'required|string',
                'state' => 'required|string',
                'lga' => 'required|string',
                'account_number' => 'nullable|string|max:20',
                'bank_name' => 'nullable|string|max:255',
                'bank_code' => 'nullable|string|max:10',
                'account_name' => 'nullable|string|max:255',
                'profile_image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
                'nin_front_image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
                'nin_back_image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
                'notes' => 'nullable|string',
            ]);

            // Check if another agent already exists for this state (excluding current agent)
            $existingAgent = \App\Models\Agent::where('state', $request->state)
                ->where('status', '!=', 'deleted')
                ->where('id', '!=', $agent->id)
                ->first();

            if ($existingAgent) {
                return response()->json([
                    'status' => false,
                    'message' => "Another agent already exists for {$request->state} state. Only one agent is allowed per state.",
                    'existing_agent' => [
                        'name' => $existingAgent->first_name . ' ' . $existingAgent->last_name,
                        'email' => $existingAgent->email,
                        'state' => $existingAgent->state
                    ]
                ], 422);
            }

            $agentData = $request->only([
                'first_name', 'last_name', 'email', 'phone', 'address',
                'state', 'lga', 'account_number', 'bank_name', 'bank_code', 'account_name',
                'notes'
            ]);

            // Handle file uploads
            if ($request->hasFile('profile_image')) {
                $profileImage = $request->file('profile_image');
                $profileImageName = time() . '_profile_' . $profileImage->getClientOriginalName();
                
                // Create directory if it doesn't exist
                $directory = public_path('images/agents');
                if (!file_exists($directory)) {
                    mkdir($directory, 0755, true);
                }
                
                // Move file to public/images/agents directory
                $profileImage->move($directory, $profileImageName);
                $agentData['profile_image'] = 'images/agents/' . $profileImageName;
            }

            if ($request->hasFile('nin_front_image')) {
                $ninFrontImage = $request->file('nin_front_image');
                $ninFrontImageName = time() . '_nin_front_' . $ninFrontImage->getClientOriginalName();
                
                $directory = public_path('images/agents');
                if (!file_exists($directory)) {
                    mkdir($directory, 0755, true);
                }
                
                $ninFrontImage->move($directory, $ninFrontImageName);
                $agentData['nin_front_image'] = 'images/agents/' . $ninFrontImageName;
            }

            if ($request->hasFile('nin_back_image')) {
                $ninBackImage = $request->file('nin_back_image');
                $ninBackImageName = time() . '_nin_back_' . $ninBackImage->getClientOriginalName();
                
                $directory = public_path('images/agents');
                if (!file_exists($directory)) {
                    mkdir($directory, 0755, true);
                }
                
                $ninBackImage->move($directory, $ninBackImageName);
                $agentData['nin_back_image'] = 'images/agents/' . $ninBackImageName;
            }

            $agent->update($agentData);

            return response()->json([
                'status' => true,
                'message' => 'Agent updated successfully',
                'data' => $agent
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            $errors = $e->errors();
            $errorMessages = [];
            
            foreach ($errors as $field => $messages) {
                foreach ($messages as $message) {
                    if (str_contains($message, 'email') && str_contains($message, 'unique')) {
                        $errorMessages[] = 'This email address is already registered. Please use a different email.';
                    } elseif (str_contains($message, 'required')) {
                        $fieldName = str_replace('_', ' ', $field);
                        $errorMessages[] = ucfirst($fieldName) . ' is required.';
                    } else {
                        $errorMessages[] = $message;
                    }
                }
            }
            
            return response()->json([
                'status' => false,
                'message' => implode(' ', $errorMessages),
                'errors' => $errors
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Update agent failed: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to update agent'
            ], 500);
        }
    }

    /**
     * Get recent orders for dashboard
     */
    public function getRecentOrders()
    {
        try {
            $recentOrders = Order::with(['user', 'car', 'driverLicense', 'payment'])
                ->leftJoin('states', 'orders.state', '=', 'states.id')
                ->leftJoin('lgas', 'orders.lga', '=', 'lgas.id')
                ->select('orders.*', 'states.state_name as state_name', 'lgas.lga_name as lga_name')
                ->orderBy('orders.created_at', 'desc')
                ->limit(5)
                ->get();

            // Enhance recent orders with driver license information
            $recentOrders->transform(function ($order) {
                $orderData = $order->toArray();
                
                // Add driver license summary for recent orders
                if ($order->driver_license_id && $order->driverLicense) {
                    $paymentMetaData = $order->payment->meta_data ?? [];
                    
                    $orderData['driver_license_summary'] = [
                        'license_type' => $order->driverLicense->license_type,
                        'license_year' => $order->driverLicense->license_year,
                        'full_name' => $order->driverLicense->full_name,
                        'phone_number' => $order->driverLicense->phone_number,
                        'base_amount' => $paymentMetaData['base_amount'] ?? null,
                        'total_amount' => $paymentMetaData['total_amount'] ?? null,
                        'calculation' => ($paymentMetaData['base_amount'] ?? 0) . ' × ' . ($paymentMetaData['license_year'] ?? 0) . ' = ' . ($paymentMetaData['total_amount'] ?? 0)
                    ];
                }
                
                return $orderData;
            });

            return response()->json([
                'status' => true,
                'data' => $recentOrders
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to fetch recent orders: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get recent transactions for dashboard
     */
    public function getRecentTransactions()
    {
        try {
            $recentTransactions = \App\Models\Payment::with(['car', 'driverLicense'])
                ->whereIn('status', ['completed', 'approved'])
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->get();

            return response()->json([
                'status' => true,
                'data' => $recentTransactions
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to fetch recent transactions: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all transactions for admin payment page (showing all payment records)
     */
    public function getAllTransactions(Request $request)
    {
        $admin = Auth::user();

        if (!$admin->is_admin) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized access'
            ], 403);
        }

        $perPage = $request->get('per_page', 15);
        $status = $request->get('status', 'all');
        $search = $request->get('search', '');

        // Map frontend status to database status (Payment model statuses)
        $statusMap = [
            'all' => null,
            'success' => 'approved',
            'pending' => 'pending',
            'failed' => 'declined'
        ];

        $query = Payment::with(['user', 'car', 'driverLicense'])
            ->orderBy('created_at', 'desc');

        if ($status !== 'all' && isset($statusMap[$status])) {
            $query->where('status', $statusMap[$status]);
        }

        if ($search) {
            $query->where(function($q) use ($search) {
                $q->where('transaction_id', 'like', "%{$search}%")
                  ->orWhere('payment_description', 'like', "%{$search}%")
                  ->orWhereHas('user', function($userQuery) use ($search) {
                      $userQuery->where('name', 'like', "%{$search}%")
                               ->orWhere('email', 'like', "%{$search}%");
                  });
            });
        }

        $transactions = $query->paginate($perPage);

        // Calculate summary statistics based on payments
        $summary = [
            'total_amount' => Payment::whereIn('status', ['approved', 'completed'])->sum('amount'),
            'total_transactions' => Payment::count(),
            'successful_transactions' => Payment::whereIn('status', ['approved', 'completed'])->count(),
            'failed_transactions' => Payment::where('status', 'declined')->count(),
            'pending_transactions' => Payment::where('status', 'pending')->count(),
        ];

        return response()->json([
            'status' => true,
            'data' => $transactions,
            'summary' => $summary
        ]);
    }

    /**
     * Get failed transactions for admin payment page (showing declined payments)
     */
    public function getFailedTransactions(Request $request)
    {
        $admin = Auth::user();

        if (!$admin->is_admin) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized access'
            ], 403);
        }

        $perPage = $request->get('per_page', 10);
        $search = $request->get('search', '');

        $query = Payment::with(['user', 'car'])
            ->where('status', 'declined')
            ->orderBy('created_at', 'desc');

        if ($search) {
            $query->where(function($q) use ($search) {
                $q->where('transaction_id', 'like', "%{$search}%")
                  ->orWhere('payment_description', 'like', "%{$search}%")
                  ->orWhereHas('user', function($userQuery) use ($search) {
                      $userQuery->where('name', 'like', "%{$search}%")
                               ->orWhere('email', 'like', "%{$search}%");
                  });
            });
        }

        $transactions = $query->paginate($perPage);

        return response()->json([
            'status' => true,
            'data' => $transactions
        ]);
    }

    /**
     * Debug transactions - check what data exists
     */
    public function debugTransactions()
    {
        $admin = Auth::user();

        if (!$admin->is_admin) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized access'
            ], 403);
        }

        $transactionCount = \App\Models\Transaction::count();
        $paymentCount = Payment::count();
        
        $recentTransactions = \App\Models\Transaction::latest()->limit(3)->get();
        $recentPayments = Payment::latest()->limit(3)->get();

        return response()->json([
            'status' => true,
            'data' => [
                'transaction_count' => $transactionCount,
                'payment_count' => $paymentCount,
                'recent_transactions' => $recentTransactions,
                'recent_payments' => $recentPayments,
                'payment_statuses' => Payment::select('status')->distinct()->pluck('status'),
            ]
        ]);
    }

    /**
     * Update agent payment when order is completed
     */
    private function updateAgentPayment(Order $order)
    {
        try {
            $agent = Agent::find($order->agent_id);
            if (!$agent) {
                return;
            }

            // Calculate payment amount based on order type
            $paymentAmount = $this->calculateAgentPayment($order);

            // Create or update agent payment record
            $agentPayment = AgentPayment::updateOrCreate(
                [
                    'agent_id' => $agent->id,
                    'order_id' => $order->id,
                ],
                [
                    'amount' => $paymentAmount,
                    'commission_rate' => 10.00, // 10% commission rate
                    'status' => 'pending',
                    'notes' => "Payment for completed order: {$order->order_type}",
                ]
            );

            // Log the payment update
            \Log::info("Agent payment updated for order {$order->slug}", [
                'agent_id' => $agent->id,
                'order_id' => $order->id,
                'amount' => $paymentAmount,
                'payment_id' => $agentPayment->id
            ]);

        } catch (\Exception $e) {
            \Log::error("Failed to update agent payment for order {$order->slug}: " . $e->getMessage());
        }
    }

    /**
     * Calculate agent payment amount based on order type
     */
    private function calculateAgentPayment(Order $order)
    {
        // Agent gets the full order amount
        return $order->amount;
    }

    /**
     * Send payment notification to agent
     */
    private function notifyAgentPayment(Order $order, Agent $agent, $transferResult)
    {
        try {
            // Send WhatsApp notification
            $whatsappMessage = "💰 *Payment Initiated!*\n\n";
            $whatsappMessage .= "Order ID: #{$order->slug}\n";
            $whatsappMessage .= "Service: " . ucwords(str_replace('_', ' ', $order->order_type)) . "\n";
            $whatsappMessage .= "Amount: ₦" . number_format($transferResult['amount'], 2) . "\n";
            $whatsappMessage .= "Commission: ₦" . number_format($transferResult['commission_amount'], 2) . "\n";
            $whatsappMessage .= "Transfer Ref: {$transferResult['transfer_reference']}\n";
            $whatsappMessage .= "Status: Pending\n\n";
            $whatsappMessage .= "Customer Details:\n";
            $whatsappMessage .= "Name: {$order->user->firstName} {$order->user->lastName}\n";
            $whatsappMessage .= "Phone: {$order->user->phone}\n";
            $whatsappMessage .= "Address: {$order->delivery_address}\n\n";
            $whatsappMessage .= "Payment will be processed shortly. Please wait for confirmation.";

            // TODO: Implement WhatsApp sending
            \Log::info('Payment notification prepared for agent', [
                'agent_phone' => $agent->phone,
                'order_slug' => $order->slug,
                'transfer_reference' => $transferResult['transfer_reference'],
                'message' => $whatsappMessage
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to send payment notification to agent', [
                'order_slug' => $order->slug,
                'agent_id' => $agent->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Send manual payment notification to agent
     */
    private function notifyAgentManualPayment(Order $order, Agent $agent, $transferResult)
    {
        try {
            // Send WhatsApp notification for manual payment
            $whatsappMessage = "📋 *Manual Payment Required*\n\n";
            $whatsappMessage .= "Order ID: #{$order->slug}\n";
            $whatsappMessage .= "Service: " . ucwords(str_replace('_', ' ', $order->order_type)) . "\n";
            $whatsappMessage .= "Amount: ₦" . number_format($transferResult['amount'], 2) . "\n";
            $whatsappMessage .= "Commission: ₦" . number_format($transferResult['commission_amount'], 2) . "\n";
            $whatsappMessage .= "Transfer Ref: {$transferResult['transfer_reference']}\n";
            $whatsappMessage .= "Status: Manual Payment Required\n\n";
            $whatsappMessage .= "Customer Details:\n";
            $whatsappMessage .= "Name: {$order->user->firstName} {$order->user->lastName}\n";
            $whatsappMessage .= "Phone: {$order->user->phone}\n";
            $whatsappMessage .= "Address: {$order->delivery_address}\n\n";
            $whatsappMessage .= "⚠️ Due to account limitations, payment will be processed manually. Please contact admin for payment confirmation.";

            // TODO: Implement WhatsApp sending
            \Log::info('Manual payment notification prepared for agent', [
                'agent_phone' => $agent->phone,
                'order_slug' => $order->slug,
                'transfer_reference' => $transferResult['transfer_reference'],
                'message' => $whatsappMessage
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to send manual payment notification to agent', [
                'order_slug' => $order->slug,
                'agent_id' => $agent->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get agent payments for a specific agent
     */
    public function getAgentPayments(Request $request, $agentId)
    {
        try {
            $agent = Agent::findOrFail($agentId);
            
            $payments = AgentPayment::where('agent_id', $agentId)
                ->with(['order.user', 'order.car'])
                ->orderBy('created_at', 'desc')
                ->paginate(15);

            return response()->json([
                'status' => true,
                'data' => [
                    'agent' => $agent,
                    'payments' => $payments,
                    'total_earnings' => AgentPayment::where('agent_id', $agentId)
                        ->where('status', 'paid')
                        ->sum('commission_amount'),
                    'pending_earnings' => AgentPayment::where('agent_id', $agentId)
                        ->where('status', 'pending')
                        ->sum('commission_amount'),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to fetch agent payments',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all agent payments (admin view)
     */
    public function getAllAgentPayments(Request $request)
    {
        try {
            $payments = AgentPayment::with(['agent', 'order.user', 'order.car'])
                ->orderBy('created_at', 'desc')
                ->paginate(15);

            return response()->json([
                'status' => true,
                'data' => $payments
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to fetch agent payments',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}