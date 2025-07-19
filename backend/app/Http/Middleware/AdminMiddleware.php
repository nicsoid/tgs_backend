<?php
// app/Http/Middleware/AdminMiddleware.php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class AdminMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string $permission = 'admin-access'): Response
    {
        if (!$request->user()) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        if (!Gate::allows($permission, $request->user())) {
            return response()->json([
                'error' => 'Admin access required',
                'message' => 'You do not have permission to access this resource.'
            ], 403);
        }

        return $next($request);
    }
}