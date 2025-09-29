<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use PragmaRX\Google2FAQRCode\Google2FA;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;

class TwoFactorController extends Controller
{
    public function enableGoogle2fa(Request $request)
    {
        $user = Auth::user();
        $google2fa = app('pragmarx.google2fa');

        $secret = $google2fa->generateSecretKey();
        $user->two_factor_secret = encrypt($secret);
        $user->two_factor_type = 'google';
        $user->save();

        $QR_Image = $google2fa->getQRCodeInline(
            config('app.name'),
            $user->email,
            $secret
        );

        return response()->json([
            'success' => true,
            'message' => 'Scan the QR code with Google Authenticator',
            'qr_code' => $QR_Image,
        ]);
    }

    public function verifyGoogle2fa(Request $request)
    {
        $request->validate(['code' => 'required|string']);
        $user = Auth::user();
        $google2fa = app('pragmarx.google2fa');
        $secret = decrypt($user->two_factor_secret);

        if ($google2fa->verifyKey($secret, $request->code)) {
            $user->two_factor_enabled = true;
            $user->save();
            return response()->json(['success' => true, 'message' => '2FA enabled']);
        }
        return response()->json(['success' => false, 'message' => 'Invalid code'], 422);
    }

    public function enableEmail2fa(Request $request)
    {
        $user = Auth::user();
        
        // Rate limiting: 10 attempts per user per 15 minutes
        $key = '2fa_enable_email_' . $user->id;
        
        if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($key, 10)) {
            $seconds = \Illuminate\Support\Facades\RateLimiter::availableIn($key);
            return response()->json([
                'success' => false,
                'message' => 'Too many 2FA enable attempts. Please wait ' . $seconds . ' seconds before trying again.',
                'retry_after' => $seconds
            ], 429);
        }
        
        $code = rand(100000, 999999);
        $user->two_factor_email_code = $code;
        $user->two_factor_email_expires_at = now()->addMinutes(10);
        $user->two_factor_type = 'email';
        $user->save();

        // Send code via email
        Mail::raw("Your 2FA code is: $code", function ($message) use ($user) {
            $message->to($user->email)->subject('Your 2FA Code');
        });

        // Increment rate limiter
        \Illuminate\Support\Facades\RateLimiter::hit($key, 900); // 15 minutes

        return response()->json(['success' => true, 'message' => '2FA code sent to your email']);
    }

    public function verifyEmail2fa(Request $request)
    {
        $request->validate(['code' => 'required|string']);
        $user = Auth::user();
        
        // Rate limiting: 10 attempts per user per 15 minutes
        $key = '2fa_verify_email_' . $user->id;
        
        if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($key, 10)) {
            $seconds = \Illuminate\Support\Facades\RateLimiter::availableIn($key);
            return response()->json([
                'success' => false,
                'message' => 'Too many 2FA verification attempts. Please wait ' . $seconds . ' seconds before trying again.',
                'retry_after' => $seconds
            ], 429);
        }

        if (
            $user->two_factor_email_code === $request->code &&
            $user->two_factor_email_expires_at &&
            now()->lessThanOrEqualTo($user->two_factor_email_expires_at)
        ) {
            $user->two_factor_enabled = true;
            $user->save();
            
            // Clear rate limiter on successful verification
            \Illuminate\Support\Facades\RateLimiter::clear($key);
            
            return response()->json(['success' => true, 'message' => '2FA enabled']);
        }
        
        // Increment rate limiter on failed verification
        \Illuminate\Support\Facades\RateLimiter::hit($key, 900); // 15 minutes
        
        return response()->json(['success' => false, 'message' => 'Invalid or expired code'], 422);
    }

    public function disable2fa(Request $request)
    {
        $request->validate([
            'type' => 'required|in:email,google',
        ]);

        $user = Auth::user();
        $type = $request->input('type');

        if ($type === 'email') {
            $user->two_factor_type = $user->two_factor_type === 'email' ? null : $user->two_factor_type;
            $user->two_factor_email_code = null;
            $user->two_factor_email_expires_at = null;
            // If Google 2FA is not enabled, disable 2FA entirely
            if ($user->two_factor_type !== 'google') {
                $user->two_factor_enabled = false;
            }
        } elseif ($type === 'google') {
            $user->two_factor_type = $user->two_factor_type === 'google' ? null : $user->two_factor_type;
            $user->two_factor_secret = null;
            // If Email 2FA is not enabled, disable 2FA entirely
            if ($user->two_factor_type !== 'email') {
                $user->two_factor_enabled = false;
            }
        }

        $user->save();

        return response()->json([
            'success' => true,
            'message' => ucfirst($type) . ' 2FA disabled'
        ]);
    }

    public function verifyLogin2fa(Request $request)
    {
        $request->validate([
            '2fa_token' => 'required|string',
            'code' => 'required|string',
        ]);

        $user = \App\Models\User::where('two_factor_login_token', $request->input('2fa_token'))
            ->where('two_factor_login_expires_at', '>=', now())
            ->first();

        if (!$user) {
            return response()->json(['status' => 'error', 'message' => 'Invalid or expired 2FA token'], 401);
        }

        if (
            $user->two_factor_email_code === $request->code &&
            $user->two_factor_email_expires_at &&
            now()->lessThanOrEqualTo($user->two_factor_email_expires_at)
        ) {
            // Clear the code and token after successful verification
            $user->two_factor_email_code = null;
            $user->two_factor_email_expires_at = null;
            $user->two_factor_login_token = null;
            $user->two_factor_login_expires_at = null;
            $user->save();

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

        return response()->json(['status' => 'error', 'message' => 'Invalid or expired code'], 422);
    }

    public function check2faStatus(Request $request)
    {
        $user = Auth::user();

        return response()->json([
            'email_2fa' => $user->two_factor_type === 'email' ? 1 : 0,
            'google_2fa' => $user->two_factor_type === 'google' ? 1 : 0,
        ]);
    }
}
