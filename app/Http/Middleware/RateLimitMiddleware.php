<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class RateLimitMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @param  string  $action
     * @param  int  $maxAttempts
     * @param  int  $decayMinutes
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next, string $action, int $maxAttempts = 10, int $decayMinutes = 15)
    {
        $identifier = $this->getIdentifier($request, $action);
        $key = "rate_limit:{$action}:{$identifier}";
        
        // Check if user is already blocked
        $blockKey = "blocked:{$action}:{$identifier}";
        if (Cache::has($blockKey)) {
            $blockedUntil = Cache::get($blockKey);
            $remainingTime = Carbon::parse($blockedUntil)->diffInSeconds(now());
            
            Log::warning('Rate limit exceeded - user blocked', [
                'action' => $action,
                'identifier' => $identifier,
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'blocked_until' => $blockedUntil,
                'remaining_seconds' => $remainingTime
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => "Account temporarily blocked due to too many attempts. Please try again in {$remainingTime} seconds.",
                'retry_after' => $remainingTime,
                'blocked_until' => $blockedUntil
            ], 429);
        }
        
        // Check rate limit
        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $seconds = RateLimiter::availableIn($key);
            
            // If this is the final attempt, block the user for 15 minutes
            if ($seconds > 0) {
                $blockedUntil = now()->addMinutes($decayMinutes);
                Cache::put($blockKey, $blockedUntil, $decayMinutes * 60);
                
                Log::critical('User account blocked due to rate limit', [
                    'action' => $action,
                    'identifier' => $identifier,
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'blocked_until' => $blockedUntil,
                    'max_attempts' => $maxAttempts
                ]);
                
                return response()->json([
                    'status' => 'error',
                    'message' => "Account blocked for {$decayMinutes} minutes due to too many attempts. Please try again later.",
                    'retry_after' => $decayMinutes * 60,
                    'blocked_until' => $blockedUntil
                ], 429);
            }
            
            return response()->json([
                'status' => 'error',
                'message' => "Too many attempts. Please wait {$seconds} seconds before trying again.",
                'retry_after' => $seconds
            ], 429);
        }
        
        // Increment rate limiter
        RateLimiter::hit($key, $decayMinutes * 60);
        
        return $next($request);
    }
    
    /**
     * Get identifier for rate limiting
     */
    private function getIdentifier(Request $request, string $action): string
    {
        // For email-based actions, use email as identifier
        if ($request->has('email') && $request->email) {
            return 'email:' . $request->email;
        }
        
        // For phone-based actions, use phone as identifier
        if ($request->has('phone_number') && $request->phone_number) {
            return 'phone:' . $request->phone_number;
        }
        
        // For authenticated users, use user ID
        if ($request->user()) {
            return 'user:' . $request->user()->id;
        }
        
        // Fallback to IP address
        return 'ip:' . $request->ip();
    }
}
