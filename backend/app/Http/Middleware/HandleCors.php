<?php

// app/Http/Middleware/HandleCors.php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class HandleCors
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        $origin = $request->headers->get('Origin');
        
        // Allow ngrok domains
        if ($origin && (
            str_contains($origin, 'ngrok-free.app') || 
            str_contains($origin, 'ngrok.io') ||
            str_contains($origin, 'localhost')
        )) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
            $response->headers->set('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, X-Token-Auth, Authorization');
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
        }

        return $response;
    }
}