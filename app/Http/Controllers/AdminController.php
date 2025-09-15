<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Payment;
use App\Models\Car;
use App\Models\Agent;
use App\Models\Order;
use App\Models\AgentPayment;
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

        $query = Order::with(['user', 'car', 'payment', 'agent'])
            ->leftJoin('states', 'orders.state', '=', 'states.id')
            ->leftJoin('lgas', 'orders.lga', '=', 'lgas.id')
            ->select('orders.*', 'states.state_name as state_name', 'lgas.lga_name as lga_name')
            ->orderBy('orders.created_at', 'desc');

        if ($status !== 'all' && isset($statusMap[$status])) {
            $query->where('status', $statusMap[$status]);
        }

        $orders = $query->paginate($perPage);

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

        $order = Order::with(['user', 'car', 'payment', 'agent'])
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

        return response()->json([
            'status' => true,
            'data' => $order
        ]);
    }

    /**
     * Process order - assign to agent based on state
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

        // Assign agent to order
        $order->update([
            'agent_id' => $agent->id,
            'status' => 'in_progress',
            'processed_at' => now(),
            'processed_by' => $admin->id,
        ]);

        // Send notification to agent (WhatsApp and Email)
        $this->notifyAgent($order, $agent);

        return response()->json([
            'status' => true,
            'message' => 'Order assigned to agent successfully',
            'data' => [
                'order' => $order->load('agent'),
                'agent' => $agent
            ]
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

        $perPage = $request->get('per_page', 15);

        $cars = Car::with('user')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return response()->json([
            'status' => true,
            'data' => $cars
        ]);
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

        $car = Car::with('user')->where('slug', $slug)->first();

        if (!$car) {
            return response()->json([
                'status' => false,
                'message' => 'Car not found'
            ], 404);
        }

        return response()->json([
            'status' => true,
            'data' => $car
        ]);
    }

    /**
     * Notify agent about new order
     */
    private function notifyAgent($order, $agent)
    {
        try {
            // Get state and LGA names
            $state = \App\Models\State::find($order->state);
            $lga = \App\Models\Lga::find($order->lga);
            
            $stateName = $state ? $state->state_name : 'Unknown State';
            $lgaName = $lga ? $lga->lga_name : 'Unknown LGA';
            
            // WhatsApp notification (placeholder - you'd integrate with WhatsApp API)
            $whatsappMessage = "New Order Assigned!\n\n";
            $whatsappMessage .= "Order ID: {$order->slug}\n";
            $whatsappMessage .= "Customer: {$order->user->name}\n";
            $whatsappMessage .= "Car: {$order->car->vehicle_make} {$order->car->vehicle_model}\n";
            $whatsappMessage .= "Amount: ₦{$order->amount}\n";
            $whatsappMessage .= "Address: {$order->delivery_address}\n";
            $whatsappMessage .= "Contact: {$order->delivery_contact}\n";
            $whatsappMessage .= "State: {$stateName}\n";
            $whatsappMessage .= "LGA: {$lgaName}\n";

            // Email notification
            $emailData = [
                'agent' => $agent,
                'order' => $order,
                'whatsapp_message' => $whatsappMessage,
                'stateName' => $stateName,
                'lgaName' => $lgaName
            ];

            Mail::send('emails.agent-order-notification', $emailData, function ($message) use ($agent, $order) {
                $message->to($agent->email)
                    ->subject("New Order Assigned - {$order->slug}");
            });

            Log::info('Agent notified about new order', [
                'agent_id' => $agent->id,
                'order_slug' => $order->slug,
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
            'account_name' => 'nullable|string|max:255',
            'profile_image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'nin_front_image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'nin_back_image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'notes' => 'nullable|string',
        ]);

        try {
            $agentData = $request->only([
                'first_name', 'last_name', 'email', 'phone', 'address',
                'state', 'lga', 'account_number', 'bank_name', 'account_name',
                'notes'
            ]);

            // Handle file uploads
            if ($request->hasFile('profile_image')) {
                $profileImage = $request->file('profile_image');
                $profileImageName = time() . '_profile_' . $profileImage->getClientOriginalName();
                $profileImage->storeAs('public/agents', $profileImageName);
                $agentData['profile_image'] = 'agents/' . $profileImageName;
            }

            if ($request->hasFile('nin_front_image')) {
                $ninFrontImage = $request->file('nin_front_image');
                $ninFrontImageName = time() . '_nin_front_' . $ninFrontImage->getClientOriginalName();
                $ninFrontImage->storeAs('public/agents', $ninFrontImageName);
                $agentData['nin_front_image'] = 'agents/' . $ninFrontImageName;
            }

            if ($request->hasFile('nin_back_image')) {
                $ninBackImage = $request->file('nin_back_image');
                $ninBackImageName = time() . '_nin_back_' . $ninBackImage->getClientOriginalName();
                $ninBackImage->storeAs('public/agents', $ninBackImageName);
                $agentData['nin_back_image'] = 'agents/' . $ninBackImageName;
            }

            $agent = \App\Models\Agent::create($agentData);

            return response()->json([
                'status' => true,
                'message' => 'Agent created successfully',
                'data' => $agent
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to create agent: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get recent orders for dashboard
     */
    public function getRecentOrders()
    {
        try {
            $recentOrders = Order::with(['user', 'car', 'payment'])
                ->leftJoin('states', 'orders.state', '=', 'states.id')
                ->leftJoin('lgas', 'orders.lga', '=', 'lgas.id')
                ->select('orders.*', 'states.state_name as state_name', 'lgas.lga_name as lga_name')
                ->orderBy('orders.created_at', 'desc')
                ->limit(5)
                ->get();

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
            $recentTransactions = \App\Models\Payment::with(['car'])
                ->where('status', 'completed')
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
        // Define payment amounts for different order types
        $paymentRates = [
            'vehicle_license' => 5000.00,
            'drivers_license' => 3000.00,
            'plate_number' => 2000.00,
            'vehicle_inspection' => 1500.00,
        ];

        return $paymentRates[$order->order_type] ?? 1000.00; // Default amount
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