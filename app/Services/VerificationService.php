<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Event;
use App\Events\VerificationEvent;

class VerificationService
{
    public function __construct()
    {}

    public function sendEmailVerification($user)
    {
        $code = Str::random(6);
        $user->email_verification_code = $code;
        $user->save();

        Event::dispatch(new VerificationEvent($user, 'email', $code));

        Mail::send('emails.verification', ['code' => $code], function ($message) use ($user) {
            $message->from('noreply@motoka.com', 'Motoka')
                    ->to($user->email)
                    ->subject('Verify Your Email Address');
        });

        return $code;
    }

    public function sendPhoneVerification($user)
    {
        $code = rand(100000, 999999);
        $user->phone_verification_code = $code;
        $user->save();

        Event::dispatch(new VerificationEvent($user, 'phone', $code));

        try {
            Mail::send('emails.phone-verification', ['code' => $code], function ($message) use ($user) {
                $message->from('noreply@motoka.com', 'Motoka')
                        ->to($user->email)
                        ->subject('Verify Your Phone Number');
            });
            return $code;
        } catch (\Exception $e) {
            Log::error('Phone verification email failed: ' . $e->getMessage());
            throw $e;
        }
    }

    public function verifyEmail($user, $code)
    {
        if ($user->email_verification_code === $code) {
            $user->email_verified_at = now();
            $user->email_verification_code = null;
            $user->save();
            return true;
        }
        return false;
    }

    public function verifyPhone($user, $code)
    {
        if ($user->phone_verification_code === (string)$code) {
            $user->phone_verified_at = now();
            $user->phone_verification_code = null;
            $user->save();
            return true;
        }
        return false;
    }
}
