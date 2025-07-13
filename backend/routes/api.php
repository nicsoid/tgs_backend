<?php
// routes/api.php - Add this route for handling media updates

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
    Route::delete('/groups/{id}', [GroupController::class, 'removeGroup']);
    Route::post('/groups/add-manually', [GroupController::class, 'addGroupManually']);
    
    // Post routes
    Route::get('/scheduled-posts/usage/stats', [ScheduledPostController::class, 'getUsageStats']);
    Route::apiResource('scheduled-posts', ScheduledPostController::class);
    
    // Special route for updating posts with media (supports multipart/form-data)
    Route::post('/scheduled-posts/{id}/update-with-media', [ScheduledPostController::class, 'update']);
    
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
}