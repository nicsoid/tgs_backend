<?php

// routes/web.php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

Route::get('/', function () {
    return response()->json([
        'message' => 'Telegram Scheduler API',
        'version' => '1.0.0',
        'documentation' => '/api/documentation'
    ]);
});

// Laravel Nova Admin Panel (if installed)
Route::get('/nova', function () {
    return redirect('/nova/dashboards/main');
});

// Health check
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now(),
        'service' => 'Telegram Scheduler'
    ]);
});

Route::get('/test-mongodb', function() {
    try {
        $connection = \DB::connection('mongodb');
        $collections = $connection->listCollections();
        return 'MongoDB connected successfully!';
    } catch (\Exception $e) {
        return 'MongoDB connection failed: ' . $e->getMessage();
    }
});

// Test MongoDB connection (remove in production)
if (app()->environment('local')) {
    Route::get('/test2-mongodb', function() {
        try {
            $connection = \DB::connection('mongodb');
            $db = $connection->getMongoDB();
            return response()->json([
                'status' => 'connected',
                'database' => $db->getDatabaseName(),
                'message' => 'MongoDB connected successfully!'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'MongoDB connection failed: ' . $e->getMessage()
            ], 500);
        }
    });
}