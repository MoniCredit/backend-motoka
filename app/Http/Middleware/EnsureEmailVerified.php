<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class EnsureEmailVerified
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        $user = Auth::user();
        
        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'Authentication required'
            ], 401);
        }
        
        // Check if email is verified
        if (is_null($user->email_verified_at)) {
            Log::warning('Unauthorized access attempt - email not verified', [
                'user_id' => $user->id,
                'email' => $user->email,
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'route' => $request->route()->getName()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Email verification required. Please verify your email before accessing this resource.',
                'requires_verification' => true,
                'email' => $user->email
            ], 403);
        }
        
        return $next($request);
    }
}
