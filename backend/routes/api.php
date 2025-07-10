<?php
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
    
    // Post routes
    Route::get('/scheduled-posts/usage/stats', [ScheduledPostController::class, 'getUsageStats']);
    Route::apiResource('scheduled-posts', ScheduledPostController::class);
    
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

Route::post('/auth/telegram-debug', function (Request $request) {
    Log::info('Telegram auth debug', $request->all());
    
    $bot_token = config('services.telegram.bot_token');
    
    return response()->json([
        'received_data' => $request->all(),
        'bot_token_exists' => !empty($bot_token),
        'bot_token_length' => strlen($bot_token),
        'bot_username' => config('services.telegram.bot_username'),
        'headers' => $request->headers->all()
    ]);
});