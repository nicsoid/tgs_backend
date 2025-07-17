<?php
// routes/api.php - Enhanced with admin verification

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\GroupController;
use App\Http\Controllers\ScheduledPostController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\StatisticsController;
use App\Http\Controllers\CalendarController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Public routes
Route::post('/auth/telegram', [AuthController::class, 'telegramAuth']);

// Stripe webhook (no auth)
Route::post('/stripe/webhook', [SubscriptionController::class, 'handleWebhook'])
    ->name('stripe.webhook');

// Authenticated routes
Route::middleware('auth:api')->group(function () {
    // User routes
    Route::get('/user', function (Request $request) {
        return $request->user();
    });
    Route::get('/user/settings', [UserController::class, 'getSettings']);
    Route::post('/user/settings', [UserController::class, 'updateSettings']);
    
    // Group routes
    Route::get('/groups', [GroupController::class, 'index']);
    Route::post('/groups/sync', [GroupController::class, 'sync']);
    Route::post('/groups/{id}/check-admin', [GroupController::class, 'checkAdminStatus']);
    Route::post('/groups/{id}/refresh', [GroupController::class, 'refreshGroupInfo']);
    Route::delete('/groups/{id}', [GroupController::class, 'removeGroup']);
    Route::post('/groups/add-manually', [GroupController::class, 'addGroupManually']);
    
    // Post routes - with admin verification middleware for create/update operations
    Route::get('/scheduled-posts/usage/stats', [ScheduledPostController::class, 'getUsageStats']);
    Route::get('/scheduled-posts', [ScheduledPostController::class, 'index']);
    Route::get('/scheduled-posts/{id}', [ScheduledPostController::class, 'show']);
    
    // Routes that require admin verification
    Route::middleware(\App\Http\Middleware\VerifyGroupAdmin::class)->group(function () {
        Route::post('/scheduled-posts', [ScheduledPostController::class, 'store']);
        Route::put('/scheduled-posts/{id}', [ScheduledPostController::class, 'update']);
        Route::post('/scheduled-posts/{id}/update-with-media', [ScheduledPostController::class, 'update']);
    });
    
    // Delete doesn't need admin verification since it's user's own post
    Route::delete('/scheduled-posts/{id}', [ScheduledPostController::class, 'destroy']);
    
    // Subscription routes
    Route::prefix('subscription')->group(function () {
        Route::get('/plans', [SubscriptionController::class, 'getPlans']);
        Route::post('/checkout', [SubscriptionController::class, 'createCheckoutSession']);
        Route::post('/cancel', [SubscriptionController::class, 'cancelSubscription']);
        Route::post('/resume', [SubscriptionController::class, 'resumeSubscription']);
        Route::get('/payment-history', [SubscriptionController::class, 'getPaymentHistory']);
    });
    
    // Statistics routes
    Route::get('/statistics', [StatisticsController::class, 'index']);
    Route::get('/statistics/post/{id}', [StatisticsController::class, 'postDetails']);
    
    // Calendar routes
    Route::get('/calendar', [CalendarController::class, 'getCalendarData']);
});

// Telegram webhook
Route::post('/telegram/webhook', function (Request $request) {
    // Handle Telegram updates
    Log::info('Telegram webhook', $request->all());
    
    // Process bot additions to groups
    if ($request->has('my_chat_member')) {
        $update = $request->input('my_chat_member');
        
        if ($update['new_chat_member']['user']['username'] === config('services.telegram.bot_username')) {
            // Bot was added to a group
            $chat = $update['chat'];
            
            \App\Models\Group::updateOrCreate(
                ['telegram_id' => $chat['id']],
                [
                    'title' => $chat['title'],
                    'username' => $chat['username'] ?? null,
                    'type' => $chat['type'],
                    'photo_url' => null, // Would need additional API call
                    'member_count' => 0 // Would need additional API call
                ]
            );
        }
    }
    
    return response()->json(['ok' => true]);
});

// Health check
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now(),
        'service' => 'Telegram Scheduler API'
    ]);
});

Route::get('/debug/user-groups', function(Request $request) {
    $user = $request->user();
    
    // Check user's groups_count in usage
    $usage = $user->usage;
    
    // Check actual relationships in user_groups collection
    $userGroupRelations = \DB::connection('mongodb')
        ->table('user_groups')
        ->where('user_id', $user->id)
        ->get();
    
    // Check groups that have this user's ID stored directly (old way)
    $groupsWithDirectUserId = \App\Models\Group::where('user_id', $user->id)
        ->orWhere('user_id', 'all', [$user->id])
        ->get();
    
    return response()->json([
        'user_id' => $user->id,
        'usage_groups_count' => $usage['groups_count'] ?? 0,
        'actual_relationships_count' => count($userGroupRelations),
        'admin_relationships_count' => $userGroupRelations->where('is_admin', true)->count(),
        'relationships' => $userGroupRelations,
        'groups_with_direct_user_id_count' => $groupsWithDirectUserId->count(),
        'groups_with_direct_user_id' => $groupsWithDirectUserId->toArray(),
        'user_can_add_group' => $user->canAddGroup(),
        'subscription_plan' => $user->getSubscriptionPlan()->name ?? 'unknown'
    ]);
})->middleware('auth:api');

// Debug routes (only in local environment)
if (app()->environment('local')) {
    Route::get('/debug/media', [ScheduledPostController::class, 'debugMedia']);
    Route::get('/debug/storage', function() {
        $storagePublicPath = storage_path('app/public');
        $publicStoragePath = public_path('storage');
        
        return response()->json([
            'storage_app_public' => [
                'path' => $storagePublicPath,
                'exists' => is_dir($storagePublicPath),
                'writable' => is_writable($storagePublicPath),
            ],
            'public_storage' => [
                'path' => $publicStoragePath,
                'exists' => file_exists($publicStoragePath),
                'is_link' => is_link($publicStoragePath),
                'link_target' => is_link($publicStoragePath) ? readlink($publicStoragePath) : null,
            ],
            'media_directory' => [
                'path' => $storagePublicPath . '/media',
                'exists' => is_dir($storagePublicPath . '/media'),
                'writable' => is_writable($storagePublicPath . '/media'),
            ],
            'app_url' => config('app.url'),
            'storage_url' => Storage::url(''),
        ]);
    });
    
    // Admin verification test route
    Route::post('/debug/verify-admin/{userId}', function($userId) {
        $user = \App\Models\User::find($userId);
        if (!$user) {
            return response()->json(['error' => 'User not found'], 404);
        }
        
        $telegramService = app(\App\Services\TelegramService::class);
        $result = $telegramService->verifyUserAdminStatusForAllGroups($user);
        
        return response()->json([
            'user_id' => $user->id,
            'verification_result' => $result,
            'message' => 'Admin verification completed'
        ]);
    });
}


Route::get('/test-middleware', function() {
    try {
        $middleware = new \App\Http\Middleware\VerifyGroupAdmin();
        return response()->json(['status' => 'Middleware class exists']);
    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage()]);
    }
});