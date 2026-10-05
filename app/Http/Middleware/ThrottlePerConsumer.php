<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class ThrottlePerConsumer
{
    public function handle(Request $request, Closure $next): Response
    {
        $consumer = $request->attributes->get('consumer');
        $limit = $consumer?->rate_limit_per_minute ?? (int) config('verification.default_rate_limit', 60);
        $key = 'api:' . ($consumer?->key_id ?? $request->ip());

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            return response()->json([
                'error' => ['code' => 'rate_limited', 'message' => 'Too many requests.'],
            ], 429)->header('Retry-After', (string) RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key, 60);

        return $next($request);
    }
}
