<?php

namespace App\Http\Middleware;

use App\Models\Consumer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Service-to-service auth: `Authorization: Bearer {key_id}.{secret}`.
 * Secrets are stored SHA-256 hashed; key_id identifies the consumer.
 */
class ConsumerAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        if (! $token || ! ($consumer = Consumer::resolveFromToken($token)) || ! $consumer->isActive()) {
            return response()->json([
                'error' => ['code' => 'unauthenticated', 'message' => 'Invalid or missing API credentials.'],
            ], 401);
        }

        $request->attributes->set('consumer', $consumer);

        return $next($request);
    }
}
