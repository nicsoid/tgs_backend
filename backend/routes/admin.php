<?php
// routes/admin.php - Create this new file for admin routes

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AdminController;

/*
|--------------------------------------------------------------------------
| Admin API Routes
|--------------------------------------------------------------------------
*/

Route::prefix('admin')->middleware(['auth:api', 'admin:admin-access'])->group(function () {
    
    // Dashboard
    Route::get('/dashboard', [AdminController::class, 'dashboard']);
    Route::get('/health', [AdminController::class, 'health']);
    Route::get('/analytics', [AdminController::class, 'analytics']);

    // User Management
    Route::prefix('users')->middleware('admin:manage-users')->group(function () {
        Route::get('/', [AdminController::class, 'users']);
        Route::get('/{id}', [AdminController::class, 'userDetails']);
        Route::put('/{id}', [AdminController::class, 'updateUser']);
        Route::delete('/{id}', [AdminController::class, 'deleteUser']);
        Route::post('/{id}/ban', [AdminController::class, 'banUser']);
        Route::post('/{id}/unban', [AdminController::class, 'unbanUser']);
        Route::post('/{id}/promote', [AdminController::class, 'promoteToAdmin']);
        Route::post('/{id}/demote', [AdminController::class, 'demoteFromAdmin']);
        Route::post('/{id}/reset-usage', [AdminController::class, 'resetUserUsage']);
    });

    // Post Management
    Route::prefix('posts')->middleware('admin:manage-posts')->group(function () {
        Route::get('/', [AdminController::class, 'posts']);
        Route::get('/{id}', [AdminController::class, 'postDetails']);
        Route::delete('/{id}', [AdminController::class, 'forceDeletePost']);
        Route::post('/{id}/cancel', [AdminController::class, 'cancelPost']);
        Route::post('/{id}/retry', [AdminController::class, 'retryPost']);
    });

    // Group Management
    Route::prefix('groups')->middleware('admin:manage-groups')->group(function () {
        Route::get('/', [AdminController::class, 'groups']);
        Route::get('/{id}', [AdminController::class, 'groupDetails']);
        Route::post('/{id}/refresh', [AdminController::class, 'refreshGroup']);
        Route::delete('/{id}', [AdminController::class, 'deleteGroup']);
        Route::get('/{id}/users', [AdminController::class, 'groupUsers']);
    });

    // Logs and Monitoring
    Route::prefix('logs')->group(function () {
        Route::get('/', [AdminController::class, 'logs']);
        Route::get('/errors', [AdminController::class, 'errorLogs']);
        Route::delete('/cleanup', [AdminController::class, 'cleanupLogs']);
    });

    // System Operations
    Route::prefix('system')->group(function () {
        Route::post('/maintenance', [AdminController::class, 'maintenanceMode']);
        Route::post('/cleanup', [AdminController::class, 'systemCleanup']);
        Route::post('/backup', [AdminController::class, 'createBackup']);
        Route::get('/settings', [AdminController::class, 'systemSettings']);
        Route::put('/settings', [AdminController::class, 'updateSystemSettings']);
    });

    // Export/Import
    Route::prefix('export')->group(function () {
        Route::get('/users', [AdminController::class, 'exportUsers']);
        Route::get('/posts', [AdminController::class, 'exportPosts']);
        Route::get('/groups', [AdminController::class, 'exportGroups']);
        Route::get('/logs', [AdminController::class, 'exportLogs']);
    });

    // Statistics
    Route::prefix('stats')->middleware('admin:view-system-stats')->group(function () {
        Route::get('/overview', [AdminController::class, 'statsOverview']);
        Route::get('/users', [AdminController::class, 'userStats']);
        Route::get('/posts', [AdminController::class, 'postStats']);
        Route::get('/revenue', [AdminController::class, 'revenueStats']);
        Route::get('/performance', [AdminController::class, 'performanceStats']);
    });
});

