<?php
// app/Http/Middleware/VerifyGroupAdmin.php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Services\TelegramService;
use Illuminate\Support\Facades\Log;

class VerifyGroupAdmin
{
    protected $telegramService;

    public function __construct(TelegramService $telegramService)
    {
        $this->telegramService = $telegramService;
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Only apply this middleware to POST and PUT requests for scheduled posts
        if (!in_array($request->method(), ['POST', 'PUT']) || 
            !str_contains($request->path(), 'scheduled-posts')) {
            return $next($request);
        }

        // Check if group_ids are being submitted
        $groupIds = $request->input('group_ids', []);
        
        if (empty($groupIds)) {
            return $next($request);
        }

        Log::info('Verifying admin status for groups in middleware', [
            'user_id' => $user->id,
            'group_ids' => $groupIds,
            'route' => $request->path(),
            'method' => $request->method()
        ]);

        try {
            // Quick verification for all user's groups
            $verificationResult = $this->telegramService->verifyUserAdminStatusForAllGroups($user);
            
            Log::info('Middleware admin verification completed', [
                'user_id' => $user->id,
                'verification_result' => $verificationResult
            ]);

            // If any groups were removed, the request should probably fail
            if ($verificationResult['removed'] > 0) {
                return response()->json([
                    'error' => 'Admin status verification failed',
                    'message' => "You've lost admin access to {$verificationResult['removed']} group(s). Please refresh and try again.",
                    'verification_result' => $verificationResult
                ], 403);
            }

        } catch (\Exception $e) {
            Log::error('Error in admin verification middleware', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Don't block the request on verification errors, but log them
            // The controller will handle detailed verification
        }

        return $next($request);
    }
}