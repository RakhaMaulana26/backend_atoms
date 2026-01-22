<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CorsMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $allowedOrigins = [
            'http://localhost:5173',
            'http://localhost:5174',
            'http://localhost:3000',
            'http://127.0.0.1:5173',
            'http://127.0.0.1:5174',
            'http://127.0.0.1:3000',
        ];

        $origin = $request->header('Origin');

        // DEBUG: Log origin yang dikirim
        \Log::debug('CORS Request Origin: ' . ($origin ?? 'null'));
        \Log::debug('CORS Request Method: ' . $request->getMethod());

        // Handle preflight OPTIONS request
        if ($request->getMethod() === 'OPTIONS') {
            if (in_array($origin, $allowedOrigins)) {
                \Log::debug('CORS: Allowed origin - ' . $origin);
                $response = response('', 200)
                    ->header('Access-Control-Allow-Origin', $origin)
                    ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS')
                    ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept, X-CSRF-TOKEN')
                    ->header('Access-Control-Allow-Credentials', 'true')
                    ->header('Access-Control-Max-Age', '604800');  // 7 days cache
                \Log::debug('CORS: Preflight response headers set');
                return $response;
            }
            \Log::debug('CORS: Blocked origin - ' . ($origin ?? 'null'));
            return response('Forbidden', 403);
        }

        // Handle actual request
        $response = $next($request);
        
        if (in_array($origin, $allowedOrigins)) {
            return $response
                ->header('Access-Control-Allow-Origin', $origin)
                ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS')
                ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept, X-CSRF-TOKEN')
                ->header('Access-Control-Allow-Credentials', 'true')
                ->header('Access-Control-Max-Age', '604800');  // 7 days cache
        }

        return $response;
    }
}
