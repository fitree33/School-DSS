<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiErrorResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->is_active === false) {
            Auth::guard('web')->logout();

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            Auth::forgetGuards();

            return ApiErrorResponse::make(
                'บัญชีผู้ใช้นี้ถูกระงับการใช้งาน',
                'account_inactive',
                Response::HTTP_FORBIDDEN,
            );
        }

        return $next($request);
    }
}
