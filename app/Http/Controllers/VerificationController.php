<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\OTP;
use App\Models\User;
use App\Mail\OTPMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use App\Services\VerificationService;


class VerificationController extends Controller
{
    protected $verificationService;

    public function __construct(VerificationService $verificationService)
    {
        $this->verificationService = $verificationService;
    }


    public function sendVerification(Request $request)
    {
        $request->validate([
            'email' => 'nullable|string|email|exists:users,email',
            'phone_number' => 'nullable|string|exists:users,phone_number',
        ]);

        if ($request->email) {
            $user = User::where('email', $request->email)->first();
            $verificationColumn = 'email_verification_code';
            $contact = $user->email;
            $expiresAtColumn = 'email_verification_code_expires_at';
        } elseif ($request->phone_number) {
            $user = User::where('phone_number', $request->phone_number)->first();
            $verificationColumn = 'phone_verification_code';
            $contact = $user->phone_number;
            $expiresAtColumn = 'phone_verification_code_expires_at';
        } else {
            return response()->json([
                'status' => 'error',
                'message' => 'Either email or phone number is required'
            ], 422);
        }

        // Generate OTP
        $verificationCode = rand(100000, 999999);
        $user->$verificationColumn = $verificationCode;
        $user->$expiresAtColumn = Carbon::now()->addMinutes(5);
        $user->save();

        if ($request->email) {
            // Send OTP via Email
            Mail::raw("Your verification code is: $verificationCode", function ($message) use ($user) {
                $message->to($user->email)->subject('Email Verification Code');
            });
        } elseif ($request->phone_number) {
            // Send OTP via SMS (using Twilio or any SMS gateway)
            try {
                // Example: Using Twilio (Replace with actual implementation)
                $this->sendSms($contact, "Your verification code is: $verificationCode");
            } catch (\Exception $e) {
                Log::error('SMS sending failed: ' . $e->getMessage());
                return response()->json([
                    'status' => 'error',
                    'message' => 'Failed to send SMS'
                ], 500);
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Verification code sent successfully'
        ]);
    }

    public function resendEmailVerification(Request $request)
    {
        $request->validate([
            'email' => 'nullable|string|email|exists:users,email',
            'phone_number' => 'nullable|string|exists:users,phone_number'
        ]);

        if ($request->email) {
            $user = User::where('email', $request->email)->first();
            $verificationColumn = 'email_verification_code';
            $contact = $user->email;
            $expiresAtColumn = 'email_verification_code_expires_at';
        } elseif ($request->phone_number) {
            $user = User::where('phone_number', $request->phone_number)->first();
            $verificationColumn = 'phone_verification_code';
            $contact = $user->phone_number;
            $expiresAtColumn = 'phone_verification_code_expires_at';
        } else {
            return response()->json([
                'status' => 'error',
                'message' => 'Either email or phone number is required'
            ], 422);
        }

        // Generate OTP
        $verificationCode = rand(100000, 999999);
        $user->$verificationColumn = $verificationCode;
        $user->$expiresAtColumn = Carbon::now()->addMinutes(5);
        $user->save();

        if ($request->email) {
            // Send OTP via Email
            Mail::raw("Your verification code is: $verificationCode", function ($message) use ($user) {
                $message->to($user->email)->subject('Email Verification Code');
            });
        } elseif ($request->phone_number) {
            // Send OTP via SMS (using Twilio or any SMS gateway)
            try {
                // Example: Using Twilio (Replace with actual implementation)
                $this->sendSms($contact, "Your verification code is: $verificationCode");
            } catch (\Exception $e) {
                Log::error('SMS sending failed: ' . $e->getMessage());
                return response()->json([
                    'status' => 'error',
                    'message' => 'Failed to send SMS'
                ], 500);
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Verification code sent successfully'
        ]);
    }

    // Example SMS sending method (Replace with actual SMS provider logic)
    private function sendSms($phoneNumber, $message)
    {
        // Implement SMS API (e.g., Twilio, Nexmo, etc.)
        Log::info("Sending SMS to $phoneNumber: $message");
    }


    /**
     * Send phone verification code
     */



    public function verifyUser(Request $request)
    {
        $request->validate([
            'email' => 'nullable|string|exists:users,email',
            'phone_number' => 'nullable|string|exists:users,phone_number',
            'code' => 'required|string|size:6'
        ]);

        // Determine whether we're verifying by email or phone
        if ($request->email) {
            $user = User::where('email', $request->email)->first();
            $verificationColumn = 'email_verification_code';
            $verifiedColumn = 'email_verified_at';
            $expiresAtColumn = 'email_verification_code_expires_at';
        } elseif ($request->phone_number) {
            $user = User::where('phone_number', $request->phone_number)->first();
            $verificationColumn = 'phone_verification_code';
            $verifiedColumn = 'phone_verified_at';
            $expiresAtColumn = 'phone_verification_code_expires_at';
        } else {
            return response()->json([
                'status' => 'error',
                'message' => 'Either email or phone number is required'
            ], 422);
        }

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User not found'
            ], 404);
        }

        // Check if already verified
        if ($user->$verifiedColumn) {
            return response()->json([
                'status' => 'error',
                'message' => ucfirst(str_replace('_', ' ', $verifiedColumn)) . ' already verified'
            ], 400);
        }

        if (!$user->$expiresAtColumn || now()->gt($user->$expiresAtColumn)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Verification code has expired,Click the resend button to get a new code'
            ], 400);
        }

        // Verify the code
        if ($user->$verificationColumn === $request->code) {
            $user->$verifiedColumn = now();
            $user->$verificationColumn = null; // Clear the verification code
            $user->$expiresAtColumn = null;
            $user->save();

            return response()->json([
                'status' => 'success',
                'message' => ucfirst(str_replace('_', ' ', $verifiedColumn)) . ' verified successfully'
            ]);
        }

        return response()->json([
            'status' => 'error',
            'message' => 'Invalid verification code'
        ], 400);
    }
}
