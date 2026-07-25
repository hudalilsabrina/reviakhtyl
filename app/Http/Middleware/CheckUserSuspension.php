<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class CheckUserSuspension
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user && $user->isSuspended()) {
            // Allow access to the suspension page itself and logout
            if ($request->routeIs('auth.suspended') || $request->routeIs('auth.logout')) {
                return $next($request);
            }

            if ($request->expectsJson()) {
                return response()->json([
                    'errors' => [
                        [
                            'code' => 'AccountSuspendedException',
                            'status' => '403',
                            'detail' => $user->suspension_reason ?? 'Your account has been suspended.',
                        ],
                    ],
                ], 403);
            }

            // Store suspension data in session and redirect to suspension view
            $request->session()->put('suspension_data', [
                'reason' => $user->suspension_reason,
                'suspended_at' => $user->suspended_at,
                'suspend_until' => $user->suspend_until,
            ]);

            return redirect()->route('auth.suspended');
        }

        return $next($request);
    }
}
