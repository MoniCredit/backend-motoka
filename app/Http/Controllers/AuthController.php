<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
//use App\Mail\SendEmailVerification;
use Illuminate\Support\Facades\DB;
use App\Mail\SendPhoneVerification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use App\Services\VerificationService;
use Laravel\Socialite\Facades\Socialite;
use Illuminate\Support\Facades\Validator;
use App\Http\Controllers\VerificationController;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

class AuthController extends Controller
{
    protected $verificationController;

    public function __construct(VerificationController $verificationController)
    {
        $this->verificationController = $verificationController;
    }

    /**
     * Generate a secure 4-character alphanumeric OTP
     */
    private function generateSecureOTP()
    {
        $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $otp = '';
        
        // Ensure at least one letter and one number
        $otp .= $characters[rand(0, 25)]; 
        $otp .= $characters[rand(26, 35)];
        
        // Fill remaining characters randomly
        for ($i = 2; $i < 4; $i++) {
            $otp .= $characters[rand(0, 35)];
        }
        
        // Shuffle the OTP to make it more random
        return str_shuffle($otp);
    }

    /**
     * Validate email input to prevent multiple email injection attacks
     */
    private function validateSingleEmail($email)
    {
        // Check for multiple @ symbols
        if (substr_count($email, '@') !== 1) {
            return false;
        }
        
        // Check for common email injection patterns
        $suspiciousPatterns = [
            '/\s/',           // Whitespace
            '/\r/',           // Carriage return
            '/\n/',           // New line
            '/\t/',           // Tab
            '/\0/',           // Null byte
            '/\x0B/',         // Vertical tab
            '/\x0C/',         // Form feed
            '/\x1B/',         // Escape
            '/\x7F/',         // Delete
            '/\x00-\x1F/',    // Control characters
            '/\x7F-\x9F/',    // Extended control characters
        ];
        
        foreach ($suspiciousPatterns as $pattern) {
            if (preg_match($pattern, $email)) {
                return false;
            }
        }
        
        // Check for multiple email addresses separated by common delimiters
        $delimiters = [',', ';', '|', '&', '&&', '||'];
        foreach ($delimiters as $delimiter) {
            if (strpos($email, $delimiter) !== false) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * Send OTP for login (alternative to password)
     */
    public function sendLoginOTP(Request $request)
    {
        // Rate limiting: 3 attempts per IP per 15 minutes
        $ip = $request->ip();
        $key = 'login_otp_' . $ip;
        
        if (RateLimiter::tooManyAttempts($key, 3)) {
            $seconds = RateLimiter::availableIn($key);
            return response()->json([
                'status' => 'error',
                'message' => 'Too many attempts. Please wait ' . $seconds . ' seconds before trying again.',
                'retry_after' => $seconds
            ], 429);
        }
        
        $validator = Validator::make($request->all(), [
            'email' => 'required|string|email|max:255',
        ], [
            'email.required' => 'Email is required.',
            'email.string' => 'Email must be a string.',
            'email.email' => 'Please provide a valid email address.',
            'email.max' => 'Email may not be greater than 255 characters.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => $validator->errors()->first()
            ], 422);
        }

        $email = trim($request->email);
        
        // Additional security validation
        if (!$this->validateSingleEmail($email)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid email format detected.'
            ], 422);
        }

        // Check if user exists
        $user = User::where('email', $email)->first();
        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'No account found with this email address.'
            ], 404);
        }

        // Check if email is verified
        if (is_null($user->email_verified_at)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Please verify your email before using OTP login.'
            ], 403);
        }

        // Generate secure OTP
        $otp = $this->generateSecureOTP();
        $expiresAt = Carbon::now()->addMinutes(10);

        // Store OTP in cache with expiration
        $otpKey = 'login_otp_' . $email;
        Cache::put($otpKey, [
            'otp' => $otp,
            'user_id' => $user->id,
            'attempts' => 0,
            'expires_at' => $expiresAt
        ], 600); // 10 minutes

        // Send OTP via email
        try {
            Mail::raw("Your login OTP is: $otp\n\nThis code will expire in 10 minutes.\n\nIf you didn't request this, please ignore this email.", function ($message) use ($email) {
                $message->to($email)
                        ->subject('Your Login OTP Code')
                        ->from(env('MAIL_FROM_ADDRESS'), env('MAIL_FROM_NAME'));
            });

            // Increment rate limiter
            RateLimiter::hit($key, 900); // 15 minutes window

            return response()->json([
                'status' => 'success',
                'message' => 'OTP sent successfully to your email.',
                'expires_in' => 600
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to send login OTP: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to send OTP. Please try again later.'
            ], 500);
        }
    }

    /**
     * Verify OTP and login user
     */
    public function verifyLoginOTP(Request $request)
    {
        // Rate limiting: 3 attempts per IP per 15 minutes
        $ip = $request->ip();
        $key = 'verify_otp_' . $ip;
        
        if (RateLimiter::tooManyAttempts($key, 3)) {
            $seconds = RateLimiter::availableIn($key);
            return response()->json([
                'status' => 'error',
                'message' => 'Too many attempts. Please wait ' . $seconds . ' seconds before trying again.',
                'retry_after' => $seconds
            ], 429);
        }

        $validator = Validator::make($request->all(), [
            'email' => 'required|string|email|max:255',
            'otp' => 'required|string|size:4|regex:/^[A-Z0-9]+$/',
        ], [
            'email.required' => 'Email is required.',
            'email.string' => 'Email must be a string.',
            'email.email' => 'Please provide a valid email address.',
            'email.max' => 'Email may not be greater than 255 characters.',
            'otp.required' => 'OTP is required.',
            'otp.string' => 'OTP must be a string.',
            'otp.size' => 'OTP must be exactly 4 characters.',
            'otp.regex' => 'OTP must contain only uppercase letters and numbers.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => $validator->errors()->first()
            ], 422);
        }

        $email = trim($request->email);
        $otp = strtoupper(trim($request->otp));
        
        // Additional security validation
        if (!$this->validateSingleEmail($email)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid email format detected.'
            ], 422);
        }

        // Get stored OTP data
        $otpKey = 'login_otp_' . $email;
        $otpData = Cache::get($otpKey);

        if (!$otpData) {
            RateLimiter::hit($key, 900);
            return response()->json([
                'status' => 'error',
                'message' => 'OTP expired or not found. Please request a new one.'
            ], 400);
        }

        // Check if OTP is expired
        if (Carbon::now()->gt($otpData['expires_at'])) {
            Cache::forget($otpKey);
            RateLimiter::hit($key, 900);
            return response()->json([
                'status' => 'error',
                'message' => 'OTP has expired. Please request a new one.'
            ], 400);
        }

        // Check OTP attempts
        if ($otpData['attempts'] >= 3) {
            Cache::forget($otpKey);
            RateLimiter::hit($key, 900);
            return response()->json([
                'status' => 'error',
                'message' => 'Too many OTP attempts. Please request a new OTP.'
            ], 400);
        }

        // Verify OTP
        if ($otpData['otp'] !== $otp) {
            // Increment attempts
            $otpData['attempts']++;
            Cache::put($otpKey, $otpData, 600);
            
            RateLimiter::hit($key, 900);
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid OTP. ' . (3 - $otpData['attempts']) . ' attempts remaining.'
            ], 400);
        }

        // OTP is valid, get user and create token
        $user = User::find($otpData['user_id']);
        if (!$user) {
            Cache::forget($otpKey);
            return response()->json([
                'status' => 'error',
                'message' => 'User not found.'
            ], 404);
        }

        // Clear OTP from cache
        Cache::forget($otpKey);

        // Create token
        $token = $user->createToken('API TOKEN')->plainTextToken;

        // Log successful OTP login
        Log::info('User logged in via OTP', [
            'user_id' => $user->id,
            'email' => $user->email,
            'ip' => $ip,
            'method' => 'otp'
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Login successful',
            'user' => $user,
            'authorization' => [
                'token' => $token,
                'type' => 'bearer',
            ]
        ]);
    }

    /**
     * Create a new user.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255', 'regex:/^[a-zA-Z\s\'-]+$/'], // Allows letters, spaces, hyphens, and apostrophes
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email', 'regex:/^[^<>]*$/'], // Disallow < and > for XSS
            'phone_number' => ['nullable', 'string', 'unique:users,phone_number', 'regex:/^[0-9\-\(\)\s\+]+$/'], // Allows digits, hyphens, parentheses, spaces, plus sign
            'password' => ['required', 'string', 'min:8', 'confirmed', 'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{8,}$/'],
            'nin' => ['nullable', 'string', 'regex:/^[a-zA-Z0-9]+$/'], // Only alphanumeric for NIN, adjust regex if a specific format is known
        ],
        [
            'name.required' => 'The name field is required.',
            'name.string' => 'The name must be a string.',
            'name.max' => 'The name may not be greater than 255 characters.',
            'name.regex' => 'The name can only contain letters, spaces, hyphens, and apostrophes.',
            
            'email.required' => 'The email field is required.',
            'email.string' => 'The email must be a string.',
            'email.email' => 'The email must be a valid email address.',
            'email.max' => 'The email may not be greater than 255 characters.',
            'email.unique' => 'This email is already registered.',
            'email.regex' => 'The email contains invalid characters. Please avoid < and >.',

            'phone_number.string' => 'The phone number must be a string.',
            'phone_number.unique' => 'This phone number is already registered.',
            'phone_number.regex' => 'The phone number format is invalid. Allowed characters are digits, hyphens, parentheses, spaces, and the plus sign.',

            'password.required' => 'The password field is required.',
            'password.string' => 'The password must be a string.',
            'password.min' => 'The password must be at least 8 characters.',
            'password.confirmed' => 'The password confirmation does not match.',
            'password.regex' => 'The password must contain at least one uppercase letter, one lowercase letter, one digit, and one special character (e.g., @$!%*?&).',
            
            'nin.string' => 'The NIN must be a string.',
            'nin.regex' => 'The NIN can only contain alphanumeric characters.',
        ]);

        // Custom message for either email or phone required
        if (!$request->email && !$request->phone_number) {
            return response()->json([
                'status' => 'error',
                'message' => 'Either email or phone number is required'
            ], 422);
        }

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        
        $generateUniqueString = Str::random(6); 

        $user = User::firstOrNew([
            'userId' => $generateUniqueString,
            'name' => $request->name,
            'user_type_id' => 2,
            'email' => $request->email,
            'phone_number' => $request->phone_number,
            'password' => Hash::make($request->password),
            'nin' => $request->nin, // Save NIN if provided
        ]);

        if ($user->save()) {
            $token = $user->createToken("API TOKEN")->plainTextToken;

            // Try Monicredit wallet creation, but don't block registration/OTP if it fails
            try {
                $nameParts = explode(' ', trim($request->name));
                $firstName = $nameParts[0] ?? '';
                $lastName = isset($nameParts[1]) ? implode(' ', array_slice($nameParts, 1)) : $firstName;
                $walletPayload = [
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'phone' => $request->phone_number,
                    'email' => $request->email,
                ];
                if (!empty($request->nin)) {
                    $walletPayload['nin'] = $request->nin;
                }
                $privateKey = env('MONICREDIT_PRIVATE_KEY');
                $headers = [
                    'Authorization' => 'Bearer ' . $privateKey,
                ];
                Log::info('Monicredit wallet creation payload', ['payload' => $walletPayload, 'headers' => $headers]);
                $walletResponse = Http::withHeaders($headers)->post('https://live.backend.monicredit.com/api/v1/payment/virtual-account/create', $walletPayload);
                $walletData = $walletResponse->json();
                if (isset($walletData['status']) && $walletData['status'] === true && isset($walletData['data']['customer_id'])) {
                    $user->monicredit_customer_id = $walletData['data']['customer_id'];
                    $user->save();
                } else {
                    Log::error('Monicredit wallet creation failed', ['response' => $walletData]);
                }
                Log::info('Monicredit wallet creation response', ['user_id' => $user->id, 'response' => $walletData]);
            } catch (\Exception $e) {
                Log::error('Monicredit wallet creation exception: ' . $e->getMessage());
            }

            // Always send OTP after user is created
            try {
                $verificationRequest = new Request();
                if ($request->email) {
                    $verificationRequest->merge(['email' => $request->email]);
                }
                if ($request->phone_number) {
                    $verificationRequest->merge(['phone_number' => $request->phone_number]);
                }
                Log::info('sendVerification called', ['request' => $request->all()]);
                $this->verificationController->sendVerification($verificationRequest);
            } catch (\Exception $e) {
                Log::error('Failed to send verification code: ' . $e->getMessage());
            }

            $message = 'User created successfully. Please check your email or phone for verification code.';

            return response()->json([
                'status' => 'success',
                'message' => $message,
                'user' => $user,
                'authorization' => [
                    'token' => $token,
                    'type' => 'bearer',
                ]
            ]);
        }
    }

    /**
     * Login user and create token.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */

    public function login2(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'nullable|string|email|max:255|exists:users,email',
            'phone_number' => 'nullable|string|exists:users,phone_number',
            'password' => 'required|string|min:6',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $userQuery = User::query();
        
        if ($request->email) {
            $userQuery->where('email', $request->email);
        }

        if ($request->phone_number) {
            $userQuery->where('phone_number', $request->phone_number);
        }

        $user = $userQuery->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }
        // **Check if email is verified**
        if ($request->email && is_null($user->email_verified_at)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Please verify your email before logging in.'
            ], 403);
        }

        // 2FA: If enabled, require verification
        if ($user->two_factor_enabled && $user->two_factor_type === 'email') {
            $code = rand(100000, 999999);
            $user->two_factor_email_code = $code;
            $user->two_factor_email_expires_at = now()->addMinutes(10);
            $user->two_factor_login_token = Str::random(40);
            $user->two_factor_login_expires_at = now()->addMinutes(10);
            $user->save();

            // Send code via email
            Mail::raw("Your 2FA code is: $code", function ($message) use ($user) {
                $message->to($user->email)->subject('Your 2FA Code');
            });

            return response()->json([
                'status' => '2fa_required',
                'message' => 'A verification code has been sent to your email.',
                '2fa_token' => $user->two_factor_login_token
            ]);
        }

        $token = $user->createToken('API TOKEN')->plainTextToken;

        return response()->json([
            'status' => 'success',
            'message' => 'Login successful',
            'user' => $user,
            'authorization' => [
                'token' => $token,
                'type' => 'bearer',
            ]
        ]);
    }
    
    public function sendOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => 'Email not found'], 404);
        }

        $email = $request->email;
        $otp = rand(100000, 999999);
        $now = Carbon::now();

        // Check existing OTP
        $existingOtp = DB::table('password_reset_tokens')->where('email', $email)->first();

        if ($existingOtp) {
            $createdAt = Carbon::parse($existingOtp->created_at);
            $diffInSeconds = $createdAt->diffInSeconds($now);

            if ($diffInSeconds < 60) {
                return response()->json([
                    'status' => false,
                    'message' => 'Please wait before requesting another OTP.',
                    'remaining_seconds' => 60 - $diffInSeconds,
                ], 429);
            }

            // Update existing OTP
            DB::table('password_reset_tokens')->where('email', $email)->update([
                'otp' => $otp,
                'created_at' => $now,
            ]);
        } else {
            // Insert new OTP
            DB::table('password_reset_tokens')->insert([
                'email' => $email,
                'otp' => $otp,
                'created_at' => $now,
            ]);
        }

        try {
            Mail::raw("Use this OTP to reset your password: $otp", function ($message) use ($email) {
                $message->to($email)
                        ->subject('Your OTP Code')
                        ->from(env('MAIL_FROM_ADDRESS'), env('MAIL_FROM_NAME'));
            });

            return response()->json(['status' => true, 'message' => 'OTP sent']);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => 'Mailer error: ' . $e->getMessage()]);
        }
    }


    public function verifyOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'otp' => 'required|string',
        ]);
        
        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()], 422);
        }

        $email = $request->email;
        $otp = $request->otp;

        $record = DB::table('password_reset_tokens')
            ->where('email', $email)
            ->where('otp', $otp)
            ->orderByDesc('created_at')
            ->first();

        if (!$record) {
            return response()->json(['status' => false, 'message' => 'Invalid OTP'], 404);
        }

        $otpTime = Carbon::parse($record->created_at);
        if ($otpTime->diffInMinutes(Carbon::now()) > 15) {
            return response()->json(['status' => false, 'message' => 'OTP expired']);
        }

        $token = Str::random(10);

        DB::table('password_reset_tokens')->where('email', $email)->update([
            'token' => $token,
            'otp' => null,
        ]);

        return response()->json(['status' => true, 'message' => 'OTP verified','token' => $token]);
    }

    public function reset(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email'    => 'required|email|exists:users,email',
            'token'    => 'required|string',
            'password' => 'required|min:6|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()], 400);
        }

        $email = $request->email;
         $token = $request->token;

    // Check if the token is valid
        $record = DB::table('password_reset_tokens')->where('email', $email)->where('token', $token)->first();

        if (!$record) {
            return response()->json(['status' => false, 'message' => 'Invalid token'], 401);
        }

        $newPassword = Hash::make($request->password);

        $user = User::where('email', $email)->first();
        $user->password = $newPassword;

        if ($user->save()) {
            DB::table('password_reset_tokens')->where('email', $email)->delete();
            return response()->json(['status' => true, 'message' => 'Password updated']);
        }

        return response()->json(['status' => false, 'message' => 'Failed to update password'], 500);
    }





    /**
     * Logout user (Invalidate the token).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function logout()
    {
        auth('api')->logout();
        return response()->json([
            'status' => 'success',
            'message' => 'Successfully logged out',
        ]);
    }

    public function logout2(Request $request)
    {
        // dd('here');
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Logged out successfully'
        ]);
    }


    /**
     * Refresh a token.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function refresh()
    {
        return response()->json([
            'status' => 'success',
            'user' => auth('api')->user(),
            'authorization' => [
                'token' => auth('api')->refresh(),
                'type' => 'bearer',
            ]
        ]);
    }

    /**
     * Redirect the user to the provider authentication page.
     *
     * @param string $provider
     * @return \Illuminate\Http\JsonResponse
     */
    public function redirectToProvider($provider)
    {
        try {
            $url = Socialite::driver($provider)->redirect()->getTargetUrl();
            return response()->json(['url' => $url]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Unsupported provider'], 422);
        }
    }

    /**
     * Handle provider callback and authenticate user.
     *
     * @param string $provider
     * @return \Illuminate\Http\JsonResponse
     */
    public function handleProviderCallback($provider)
    {
        try {
            $socialUser = Socialite::driver($provider)->user();

            // Find existing user or create new
            $user = User::where('social_id', $socialUser->getId())
                ->where('social_type', $provider)
                ->first();

            if (!$user) {
                // Check if user exists with same email
                $user = User::where('email', $socialUser->getEmail())->first();

                if (!$user) {
                    // Create new user
                    $user = User::create([
                        'email_verified_at' => now(), // Social login users are pre-verified
                        'name' => $socialUser->getName(),
                        'email' => $socialUser->getEmail(),
                        'social_id' => $socialUser->getId(),
                        'social_type' => $provider,
                        'avatar' => $socialUser->getAvatar(),
                        'password' => Hash::make(Str::random(16)), // Random password for social users
                    ]);
                } else {
                    // Update existing user with social info
                    $user->update([
                        'social_id' => $socialUser->getId(),
                        'social_type' => $provider,
                        'avatar' => $socialUser->getAvatar(),
                    ]);
                }
            }

            $token = Auth::login($user);

            return response()->json([
                'status' => 'success',
                'user' => $user,
                'authorization' => [
                    'token' => $token,
                    'type' => 'bearer',
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to authenticate'], 422);
        }
    }
}
