<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Carbon\Carbon;

class ProfileController extends Controller

{
    public function show()
    {
        $user = Auth::user();
        if ($user) {
            return response()->json([
                'success' => true,
                'message' => 'Profile retrieved successfully',
                'data' => [
                    'id' => $user->id,
                    'user_type_id' => $user->user_type_id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'email_verified_at' => $user->email_verified_at,
                    'image' => $user->image ? asset('storage/' . $user->image) : null,
                    'phone_number' => $user->phone_number,
                    'social_id' => $user->social_id,
                    'social_type' => $user->social_type,
                    'social_avatar' => $user->social_avatar,
                    'address' => $user->address,
                    'gender' => $user->gender,
                    'phone_verified_at' => $user->phone_verified_at,
                    'created_at' => $user->created_at,
                    'updated_at' => $user->updated_at,
                    'user_type' => $user->userType ? $user->userType->user_type_name : null,
                    'has_pending_email_change' => !is_null($user->pending_email),
                    'pending_email' => $user->pending_email ?? null 
                ]
            ]);
        }
        return response()->json([
            'success' => false,
            'message' => 'Profile Not found',
        ]);
    }

    public function update(Request $request)
    {
        $user = Auth::user();

        $request->validate([
            'name' => 'nullable|string|max:255',
            'email' => 'nullable|email|unique:users,email,' . $user->id,
            'phone_number' => 'required|string|unique:users,phone_number,' . $user->id,
            'address' => 'sometimes|nullable|string|max:255',
            'gender' => ['sometimes', 'nullable', 'string', function ($attribute, $value, $fail) {
                if (!is_null($value) && !in_array(strtolower($value), ['male', 'female', 'other'])) {
                    $fail('The gender must be Male, Female, or Other.');
                }
            }],
            'image' => 'sometimes|nullable|image|mimes:jpeg,png,jpg,gif|max:10240',
        ]);

        $data = $request->only(['name', 'address']);
        
        
        if ($request->has('gender')) {
            $data['gender'] = strtolower($request->gender);
        }


        // Handle email change - require verification
        if ($request->has('email') && $request->email !== $user->email) {
            // Generate verification code
            $verificationCode = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            
            // Store the pending email and verification code
            $user->pending_email = $request->email;
            $user->email_verification_code = $verificationCode;
            $user->email_verification_code_expires_at = Carbon::now()->addMinutes(10);
            $user->save();

            // Send verification email to NEW email address
            try {
                Mail::send('emails.email-change-verification', [
                    'user' => $user,
                    'code' => $verificationCode,
                    'newEmail' => $request->email
                ], function ($message) use ($request) {
                    $message->to($request->email)
                        ->subject('Verify Your New Email Address - Motoka');
                });

                return response()->json([
                    'success' => true,
                    'message' => 'Verification code sent to new email address. Please verify to complete the email change.',
                    'requires_verification' => true,
                    'pending_email' => $request->email
                ]);
            } catch (\Exception $e) {
                Log::error('Email change verification failed: ' . $e->getMessage());
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to send verification email. Please try again.'
                ], 500);
            }
        }

        if ($request->hasFile('image')) {
           
            if ($user->image) {
                Storage::disk('public')->delete($user->image);
            }
                $oldPath = public_path($user->image);
                if (file_exists($oldPath)) {
                    @unlink($oldPath);
                }
                $filename = time() . '_' . uniqid() . '.' . $request->file('image')->getClientOriginalExtension();
                $request->file('image')->move(public_path('images/profile_images'), $filename);
                $data['image'] = 'images/profile_images/' . $filename;
            $data['image'] = $imagePath;
        }

        $user->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully',
            'data' => [
                'id' => $user->id,
                'user_type_id' => $user->user_type_id,
                'name' => $user->name,
                'email' => $user->email,
                    'image' => $user->image ? asset($user->image) : null,
                'image' => $user->image ? asset('storage/' . $user->image) : null,
                'phone_number' => $user->phone_number,
                'social_id' => $user->social_id,
                'social_type' => $user->social_type,
                'social_avatar' => $user->social_avatar,
                'address' => $user->address,
                'gender' => $user->gender,
                'phone_verified_at' => $user->phone_verified_at,
                'created_at' => $user->created_at,
                'updated_at' => $user->updated_at,
                'user_type' => $user->userType ? $user->userType->user_type_name : null,
                'has_pending_email_change' => !is_null($user->pending_email),
                'pending_email' => $user->pending_email ?? null
            ]
        ]);
    }

    /**
     * Verify the new email change with OTP
     */
    public function verifyEmailChange(Request $request)
    {
        $user = Auth::user();

        $request->validate([
            'code' => 'required|string|size:6',
        ]);

        // Rate limiting: Max 5 attempts per 15 minutes
        $key = 'email_verify_attempts:' . $user->id;
        $attempts = \Cache::get($key, 0);
        
        if ($attempts >= 5) {
            $remainingTime = \Cache::get($key . ':expires_at');
            $minutesLeft = $remainingTime ? Carbon::parse($remainingTime)->diffInMinutes(Carbon::now()) : 15;
            
            return response()->json([
                'success' => false,
                'message' => "Too many verification attempts. Please try again in {$minutesLeft} minutes."
            ], 429);
        }

        // Check if there's a pending email change
        if (!$user->pending_email) {
            return response()->json([
                'success' => false,
                'message' => 'No pending email change found.'
            ], 400);
        }

        // Check if verification code matches
        if ($user->email_verification_code !== $request->code) {
            // Increment failed attempts
            \Cache::put($key, $attempts + 1, Carbon::now()->addMinutes(15));
            \Cache::put($key . ':expires_at', Carbon::now()->addMinutes(15)->toDateTimeString(), Carbon::now()->addMinutes(15));
            
            $remainingAttempts = 5 - ($attempts + 1);
            
            return response()->json([
                'success' => false,
                'message' => 'Invalid verification code.',
                'remaining_attempts' => max(0, $remainingAttempts)
            ], 400);
        }

        // Check if code has expired
        if (Carbon::now()->greaterThan($user->email_verification_code_expires_at)) {
            return response()->json([
                'success' => false,
                'message' => 'Verification code has expired. Please request a new one.'
            ], 400);
        }

        // Success - Update email and clear pending data
        $user->email = $user->pending_email;
        $user->pending_email = null;
        $user->email_verification_code = null;
        $user->email_verification_code_expires_at = null;
        $user->email_verified_at = Carbon::now(); 
        $user->save();

        // Clear rate limit cache on success
        \Cache::forget($key);
        \Cache::forget($key . ':expires_at');

        return response()->json([
            'success' => true,
            'message' => 'Email updated successfully!',
            'data' => [
                'email' => $user->email
            ]
        ]);
    }


    /**
     * Resend email change verification code
     */
    public function resendEmailChangeVerification(Request $request)
    {
        $user = Auth::user();

        if (!$user->pending_email) {
            return response()->json([
                'success' => false,
                'message' => 'No pending email change found.'
            ], 400);
        }

        // Rate limiting: Max 3 resends per 30 minutes
        $key = 'email_resend_attempts:' . $user->id;
        $attempts = \Cache::get($key, 0);
        
        if ($attempts >= 3) {
            $remainingTime = \Cache::get($key . ':expires_at');
            $minutesLeft = $remainingTime ? Carbon::parse($remainingTime)->diffInMinutes(Carbon::now()) : 30;
            
            return response()->json([
                'success' => false,
                'message' => "Too many resend attempts. Please try again in {$minutesLeft} minutes."
            ], 429);
        }

        // Increment resend attempts
        \Cache::put($key, $attempts + 1, Carbon::now()->addMinutes(30));
        \Cache::put($key . ':expires_at', Carbon::now()->addMinutes(30)->toDateTimeString(), Carbon::now()->addMinutes(30));

        // Generate new verification code
        $verificationCode = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        
        $user->email_verification_code = $verificationCode;
        $user->email_verification_code_expires_at = Carbon::now()->addMinutes(10);
        $user->save();

        // Send verification email
        try {
            Mail::send('emails.email-change-verification', [
                'user' => $user,
                'code' => $verificationCode,
                'newEmail' => $user->pending_email
            ], function ($message) use ($user) {
                $message->to($user->pending_email)
                    ->subject('Verify Your New Email Address - Motoka');
            });

            return response()->json([
                'success' => true,
                'message' => 'Verification code resent successfully.',
                'remaining_resends' => max(0, 3 - ($attempts + 1))
            ]);
        } catch (\Exception $e) {
            Log::error('Resend email verification failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to resend verification email.'
            ], 500);
        }
    }

      /**
     * Cancel pending email change
     */
    public function cancelEmailChange(Request $request)
    {
        $user = Auth::user();

        $user->pending_email = null;
        $user->email_verification_code = null;
        $user->email_verification_code_expires_at = null;
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Email change cancelled successfully.'
        ]);
    }



    public function changePassword(Request $request)
    {
        $user = Auth::user();

        $request->validate([
            'old_password' => 'required',
            'new_password' => 'required|min:8|confirmed',
        ]);

        if (!Hash::check($request->old_password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Old password is incorrect',
            ]);
        }

        $user->update(['password' => Hash::make($request->new_password)]);

        return response()->json([
            'success' => true,
            'message' => 'Password changed successfully',
        ]);
    }

    public function deleteAccount(Request $request)
    {
        $user = Auth::user();

        // Confirm deletion by requiring password re-entry
        $request->validate([
            'password' => 'required',
        ]);

        if (!Hash::check($request->password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Password is incorrect',
            ], 400);
        }

        // Log the deletion
        Log::info('User account deleted', ['user_id' => $user->id]);

        // Soft delete the user
        $user->update(['deleted_at' => now()]);

        return response()->json([
            'success' => true,
            'message' => 'Account deleted successfully',
        ]);
    }

    public function restoreAccount(Request $request)
    {
        // Validate the request
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        // Find the soft-deleted user
        $user = User::withTrashed()->where('email', $request->email)->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found',
            ], 404);
        }

        // Check the password
        if (!Hash::check($request->password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Password is incorrect',
            ], 400);
        }

        // Restore the user
        $user->update(['deleted_at' => null]);

        return response()->json([
            'success' => true,
            'message' => 'Account restored successfully',
            'data' => $user,
        ]);
    }


     // ...existing code...
    /**
     * KYC endpoint: update NIN and phone_number, and trigger Monicredit wallet creation if both are present.
     */
    public function storeKyc(Request $request)
    {
        $user = Auth::user();
        $validated = $request->validate([
            'nin' => 'required|string',
            'phone_number' => 'required|string|unique:users,phone_number,' . $user->id,
        ]);

        $user->nin = $validated['nin'];
        $user->phone_number = $validated['phone_number'];
        $user->save();

        // Monicredit wallet creation if not already created
        if (empty($user->monicredit_customer_id)) {
            try {
                $nameParts = explode(' ', trim($user->name));
                $firstName = $nameParts[0] ?? '';
                $lastName = isset($nameParts[1]) ? implode(' ', array_slice($nameParts, 1)) : $firstName;
                $walletPayload = [
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'phone' => $user->phone_number,
                    'email' => $user->email,
                    'nin' => $user->nin,
                ];
                $privateKey = env('MONICREDIT_PRIVATE_KEY');
                $headers = [
                    'Authorization' => 'Bearer ' . $privateKey,
                ];
                \Log::info('Monicredit wallet creation payload (KYC)', ['payload' => $walletPayload, 'headers' => $headers]);
                $walletResponse = \Http::withHeaders($headers)->post('https://live.backend.monicredit.com/api/v1/payment/virtual-account/create', $walletPayload);
                $walletData = $walletResponse->json();
                if (isset($walletData['status']) && $walletData['status'] === true && isset($walletData['data']['customer_id'])) {
                    $user->monicredit_customer_id = $walletData['data']['customer_id'];
                    $user->save();
                } else {
                    \Log::error('Monicredit wallet creation failed (KYC)', ['response' => $walletData]);
                }
                \Log::info('Monicredit wallet creation response (KYC)', ['user_id' => $user->id, 'response' => $walletData]);
            } catch (\Exception $e) {
                \Log::error('Monicredit wallet creation exception (KYC): ' . $e->getMessage());
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'KYC updated successfully',
            'data' => [
                'nin' => $user->nin,
                'phone_number' => $user->phone_number,
                'monicredit_customer_id' => $user->monicredit_customer_id ?? null,
            ]
        ]);
    }
}
