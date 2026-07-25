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
            Auth::logout();

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

            return redirect()->route('auth.login')
                ->withErrors(['suspended' => $user->suspension_reason ?? 'Your account has been suspended.']);
        }

        return $next($request);
    }
}
