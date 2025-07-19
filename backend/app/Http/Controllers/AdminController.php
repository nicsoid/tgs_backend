<?php
// app/Http/Controllers/AdminController.php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Group;
use App\Models\ScheduledPost;
use App\Models\PostLog;
use App\Models\PaymentHistory;
use App\Services\TelegramService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AdminController extends Controller
{
    protected $telegramService;

    public function __construct(TelegramService $telegramService)
    {
        $this->telegramService = $telegramService;
    }

    /**
     * Get admin dashboard overview
     */
    public function dashboard()
    {
        $stats = [
            'users' => [
                'total' => User::count(),
                'active_last_7_days' => User::where('auth_date', '>=', Carbon::now()->subDays(7))->count(),
                'new_this_month' => User::where('created_at', '>=', Carbon::now()->startOfMonth())->count(),
                'by_plan' => User::select(DB::raw('COALESCE(subscription.plan, "free") as plan'), DB::raw('count(*) as count'))
                    ->groupBy(DB::raw('COALESCE(subscription.plan, "free")'))
                    ->get()
            ],
            'groups' => [
                'total' => Group::count(),
                'total_members' => Group::sum('member_count'),
                'average_members' => Group::avg('member_count'),
                'recent' => Group::where('created_at', '>=', Carbon::now()->subDays(7))->count()
            ],
            'posts' => [
                'total' => ScheduledPost::count(),
                'pending' => ScheduledPost::where('status', 'pending')->count(),
                'completed' => ScheduledPost::where('status', 'completed')->count(),
                'failed' => ScheduledPost::where('status', 'failed')->count(),
                'this_month' => ScheduledPost::where('created_at', '>=', Carbon::now()->startOfMonth())->count()
            ],
            'messages' => [
                'total_sent' => PostLog::where('status', 'sent')->count(),
                'failed' => PostLog::where('status', 'failed')->count(),
                'success_rate' => $this->calculateSuccessRate(),
                'sent_today' => PostLog::where('status', 'sent')
                    ->where('sent_at', '>=', Carbon::today())->count()
            ],
            'revenue' => [
                'total' => PaymentHistory::where('status', 'succeeded')->sum('amount'),
                'this_month' => PaymentHistory::where('status', 'succeeded')
                    ->where('created_at', '>=', Carbon::now()->startOfMonth())->sum('amount'),
                'by_plan' => PaymentHistory::where('status', 'succeeded')
                    ->select('plan', DB::raw('sum(amount) as total'))
                    ->groupBy('plan')
                    ->get()
            ]
        ];

        return response()->json($stats);
    }

    /**
     * Get users with filtering and pagination
     */
    public function users(Request $request)
    {
        $query = User::query();

        // Apply filters
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('username', 'like', "%{$search}%")
                  ->orWhere('telegram_id', 'like', "%{$search}%");
            });
        }

        if ($request->filled('plan')) {
            $query->where('subscription.plan', $request->plan);
        }

        if ($request->filled('status')) {
            if ($request->status === 'active') {
                $query->where('auth_date', '>=', Carbon::now()->subDays(30));
            } elseif ($request->status === 'inactive') {
                $query->where('auth_date', '<', Carbon::now()->subDays(30));
            }
        }

        // Sort
        $sortBy = $request->get('sort_by', 'created_at');
        $sortDir = $request->get('sort_dir', 'desc');
        
        if (in_array($sortBy, ['created_at', 'auth_date', 'first_name', 'username'])) {
            $query->orderBy($sortBy, $sortDir);
        }

        $users = $query->paginate($request->get('per_page', 20));

        // Add computed fields
        $users->getCollection()->transform(function ($user) {
            $user->posts_count = $user->scheduledPosts()->count();
            $user->groups_count = $user->usage['groups_count'] ?? 0;
            $user->last_active = $user->auth_date;
            return $user;
        });

        return response()->json($users);
    }

    /**
     * Get user details
     */
    public function userDetails($userId)
    {
        $user = User::findOrFail($userId);
        
        $details = [
            'user' => $user,
            'posts' => $user->scheduledPosts()->orderBy('created_at', 'desc')->limit(10)->get(),
            'groups' => $this->getUserGroups($user),
            'payments' => $user->paymentHistory()->orderBy('created_at', 'desc')->limit(10)->get(),
            'stats' => [
                'total_posts' => $user->scheduledPosts()->count(),
                'messages_sent' => PostLog::whereHas('post', function($q) use ($user) {
                    $q->where('user_id', $user->id);
                })->where('status', 'sent')->count(),
                'total_revenue' => $user->paymentHistory()->where('status', 'succeeded')->sum('amount'),
                'last_login' => $user->auth_date
            ]
        ];

        return response()->json($details);
    }

    /**
     * Update user (admin actions)
     */
    public function updateUser(Request $request, $userId)
    {
        $user = User::findOrFail($userId);
        
        $request->validate([
            'subscription.plan' => 'sometimes|in:free,pro,ultra',
            'settings.is_admin' => 'sometimes|boolean',
            'is_banned' => 'sometimes|boolean'
        ]);

        if ($request->has('subscription.plan')) {
            $subscription = $user->subscription;
            $subscription['plan'] = $request->input('subscription.plan');
            $user->subscription = $subscription;
        }

        if ($request->has('settings.is_admin')) {
            $settings = $user->settings;
            $settings['is_admin'] = $request->input('settings.is_admin');
            $user->settings = $settings;
        }

        if ($request->has('is_banned')) {
            $settings = $user->settings;
            $settings['is_banned'] = $request->boolean('is_banned');
            $user->settings = $settings;
        }

        $user->save();

        return response()->json([
            'message' => 'User updated successfully',
            'user' => $user
        ]);
    }

    /**
     * Get system posts with admin controls
     */
    public function posts(Request $request)
    {
        $query = ScheduledPost::with(['user:id,first_name,last_name,username']);

        // Apply filters
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->date_to);
        }

        $posts = $query->orderBy('created_at', 'desc')
                      ->paginate($request->get('per_page', 20));

        // Add group information
        $posts->getCollection()->transform(function ($post) {
            if ($post->group_ids) {
                $post->groups = Group::whereIn('_id', $post->group_ids)->get();
            }
            return $post;
        });

        return response()->json($posts);
    }

    /**
     * Force delete a post (admin only)
     */
    public function forceDeletePost($postId)
    {
        $post = ScheduledPost::findOrFail($postId);
        
        // Delete media files
        if (isset($post->content['media'])) {
            foreach ($post->content['media'] as $mediaItem) {
                if (isset($mediaItem['path']) && \Storage::disk('public')->exists($mediaItem['path'])) {
                    \Storage::disk('public')->delete($mediaItem['path']);
                }
            }
        }

        // Delete logs
        PostLog::where('post_id', $post->id)->delete();
        
        // Delete post
        $post->delete();

        return response()->json(['message' => 'Post deleted successfully']);
    }

    /**
     * Get system groups
     */
    public function groups(Request $request)
    {
        $query = Group::query();

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('username', 'like', "%{$search}%")
                  ->orWhere('telegram_id', 'like', "%{$search}%");
            });
        }

        $groups = $query->orderBy('member_count', 'desc')
                       ->paginate($request->get('per_page', 20));

        // Add admin counts
        $groups->getCollection()->transform(function ($group) {
            $group->admin_count = DB::connection('mongodb')
                ->table('user_groups')
                ->where('group_id', $group->id)
                ->where('is_admin', true)
                ->count();
            
            $group->posts_count = ScheduledPost::whereJsonContains('group_ids', $group->id)->count();
            
            return $group;
        });

        return response()->json($groups);
    }

    /**
     * Refresh group information
     */
    public function refreshGroup($groupId)
    {
        $group = Group::findOrFail($groupId);
        
        try {
            $chatInfo = $this->telegramService->getChatInfo($group->telegram_id);
            
            if ($chatInfo) {
                $memberCount = 0;
                try {
                    $memberCount = $this->telegramService->getChatMemberCount($group->telegram_id);
                } catch (\Exception $e) {
                    $memberCount = $group->member_count ?? 0;
                }
                
                $group->update([
                    'title' => $chatInfo['title'],
                    'username' => $chatInfo['username'] ?? null,
                    'type' => $chatInfo['type'],
                    'member_count' => $memberCount,
                    'updated_at' => now()
                ]);
                
                return response()->json([
                    'message' => 'Group refreshed successfully',
                    'group' => $group
                ]);
            } else {
                return response()->json([
                    'error' => 'Could not fetch group information from Telegram'
                ], 400);
            }
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to refresh group',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get system logs
     */
    public function logs(Request $request)
    {
        $query = PostLog::with(['post:id,user_id,content', 'group:id,title,username']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('date_from')) {
            $query->where('sent_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->where('sent_at', '<=', $request->date_to);
        }

        $logs = $query->orderBy('sent_at', 'desc')
                     ->paginate($request->get('per_page', 50));

        return response()->json($logs);
    }

    /**
     * Get system analytics
     */
    public function analytics(Request $request)
    {
        $period = $request->get('period', '30'); // days
        $startDate = Carbon::now()->subDays($period);

        $analytics = [
            'user_growth' => $this->getUserGrowthData($startDate),
            'message_volume' => $this->getMessageVolumeData($startDate),
            'revenue_data' => $this->getRevenueData($startDate),
            'error_rates' => $this->getErrorRates($startDate),
            'top_groups' => $this->getTopGroups(),
            'top_users' => $this->getTopUsers()
        ];

        return response()->json($analytics);
    }

    /**
     * Export data
     */
    public function export(Request $request)
    {
        $type = $request->get('type', 'users');
        
        switch ($type) {
            case 'users':
                return $this->exportUsers($request);
            case 'posts':
                return $this->exportPosts($request);
            case 'logs':
                return $this->exportLogs($request);
            default:
                return response()->json(['error' => 'Invalid export type'], 400);
        }
    }

    /**
     * System health check
     */
    public function health()
    {
        $health = [
            'database' => $this->checkDatabase(),
            'telegram_bot' => $this->checkTelegramBot(),
            'storage' => $this->checkStorage(),
            'queue' => $this->checkQueue(),
            'recent_errors' => $this->getRecentErrors()
        ];

        $overall = collect($health)->every(fn($check) => $check['status'] === 'ok');

        return response()->json([
            'status' => $overall ? 'healthy' : 'degraded',
            'checks' => $health,
            'timestamp' => now()
        ]);
    }

    // Helper methods
    private function calculateSuccessRate()
    {
        $total = PostLog::count();
        if ($total === 0) return 100; // Return 100% if no logs yet
        
        $successful = PostLog::where('status', 'sent')->count();
        return round(($successful / $total) * 100, 2);
    }

    private function getUserGroups(User $user)
    {
        $userGroupRelations = DB::connection('mongodb')
            ->table('user_groups')
            ->where('user_id', $user->id)
            ->where('is_admin', true)
            ->get();

        $groupIds = $userGroupRelations->pluck('group_id')->toArray();
        return Group::whereIn('_id', $groupIds)->get();
    }

    private function getUserGrowthData($startDate)
    {
        return User::where('created_at', '>=', $startDate)
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('count(*) as count'))
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date')
            ->get();
    }

    private function getMessageVolumeData($startDate)
    {
        return PostLog::where('sent_at', '>=', $startDate)
            ->select(DB::raw('DATE(sent_at) as date'), DB::raw('count(*) as count'))
            ->groupBy(DB::raw('DATE(sent_at)'))
            ->orderBy('date')
            ->get();
    }

    private function getRevenueData($startDate)
    {
        return PaymentHistory::where('status', 'succeeded')
            ->where('created_at', '>=', $startDate)
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('sum(amount) as amount'))
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date')
            ->get();
    }

    private function getErrorRates($startDate)
    {
        return PostLog::where('sent_at', '>=', $startDate)
            ->select(
                DB::raw('DATE(sent_at) as date'),
                DB::raw('sum(case when status = "failed" then 1 else 0 end) as failed'),
                DB::raw('count(*) as total')
            )
            ->groupBy(DB::raw('DATE(sent_at)'))
            ->orderBy('date')
            ->get()
            ->map(function ($item) {
                $item->error_rate = $item->total > 0 ? round(($item->failed / $item->total) * 100, 2) : 0;
                return $item;
            });
    }

    private function getTopGroups()
    {
        return Group::select('id', 'title', 'username', 'member_count')
            ->orderBy('member_count', 'desc')
            ->limit(10)
            ->get();
    }

    private function getTopUsers()
    {
        return User::select('id', 'first_name', 'last_name', 'username')
            ->withCount('scheduledPosts')
            ->orderBy('scheduled_posts_count', 'desc')
            ->limit(10)
            ->get();
    }

    private function checkDatabase()
    {
        try {
            DB::connection('mongodb')->getPdo();
            return ['status' => 'ok', 'message' => 'Database connected'];
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    private function checkTelegramBot()
    {
        try {
            $botInfo = $this->telegramService->getBotInfo();
            return $botInfo ? 
                ['status' => 'ok', 'message' => 'Bot is responsive'] :
                ['status' => 'error', 'message' => 'Bot not responding'];
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    private function checkStorage()
    {
        try {
            $path = storage_path('app/public');
            return is_writable($path) ?
                ['status' => 'ok', 'message' => 'Storage is writable'] :
                ['status' => 'error', 'message' => 'Storage is not writable'];
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    private function checkQueue()
    {
        try {
            // Check if there are stuck jobs
            $stuckJobs = DB::table('jobs')
                ->where('created_at', '<', Carbon::now()->subHours(1))
                ->count();
            
            return $stuckJobs === 0 ?
                ['status' => 'ok', 'message' => 'Queue is healthy'] :
                ['status' => 'warning', 'message' => "{$stuckJobs} stuck jobs detected"];
        } catch (\Exception $e) {
            return ['status' => 'ok', 'message' => 'Queue status unknown'];
        }
    }

    private function getRecentErrors()
    {
        return PostLog::where('status', 'failed')
            ->where('sent_at', '>=', Carbon::now()->subHours(24))
            ->orderBy('sent_at', 'desc')
            ->limit(10)
            ->get(['error_message', 'sent_at', 'post_id']);
    }

    private function exportUsers($request)
    {
        // Implementation for user export
        $users = User::all();
        // Return CSV or Excel format
        return response()->json(['message' => 'Export functionality coming soon']);
    }

    private function exportPosts($request)
    {
        // Implementation for posts export
        return response()->json(['message' => 'Export functionality coming soon']);
    }

    private function exportLogs($request)
    {
        // Implementation for logs export
        return response()->json(['message' => 'Export functionality coming soon']);
    }


    /**
     * Ban a user
     */
    public function banUser($userId)
    {
        $user = User::findOrFail($userId);
        
        $settings = $user->settings;
        $settings['is_banned'] = true;
        $settings['banned_at'] = now();
        $settings['banned_by'] = request()->user()->id;
        $user->settings = $settings;
        $user->save();

        return response()->json([
            'message' => 'User banned successfully',
            'user' => $user
        ]);
    }

    /**
     * Unban a user
     */
    public function unbanUser($userId)
    {
        $user = User::findOrFail($userId);
        
        $settings = $user->settings;
        unset($settings['is_banned'], $settings['banned_at'], $settings['banned_by']);
        $user->settings = $settings;
        $user->save();

        return response()->json([
            'message' => 'User unbanned successfully',
            'user' => $user
        ]);
    }

    /**
     * Promote user to admin
     */
    public function promoteToAdmin($userId)
    {
        $user = User::findOrFail($userId);
        
        $settings = $user->settings;
        $settings['is_admin'] = true;
        $settings['promoted_at'] = now();
        $settings['promoted_by'] = request()->user()->id;
        $user->settings = $settings;
        $user->save();

        return response()->json([
            'message' => 'User promoted to admin successfully',
            'user' => $user
        ]);
    }

    /**
     * Demote user from admin
     */
    public function demoteFromAdmin($userId)
    {
        $user = User::findOrFail($userId);
        
        $settings = $user->settings;
        unset($settings['is_admin'], $settings['promoted_at'], $settings['promoted_by']);
        $user->settings = $settings;
        $user->save();

        return response()->json([
            'message' => 'User demoted from admin successfully',
            'user' => $user
        ]);
    }

    /**
     * Delete a user
     */
    public function deleteUser($userId)
    {
        $user = User::findOrFail($userId);
        
        // Prevent self-deletion
        if ($user->id === request()->user()->id) {
            return response()->json([
                'error' => 'Cannot delete your own account'
            ], 400);
        }

        // Delete user's posts and associated data
        $posts = $user->scheduledPosts()->get();
        foreach ($posts as $post) {
            // Delete media files
            if (isset($post->content['media'])) {
                foreach ($post->content['media'] as $mediaItem) {
                    if (isset($mediaItem['path']) && \Storage::disk('public')->exists($mediaItem['path'])) {
                        \Storage::disk('public')->delete($mediaItem['path']);
                    }
                }
            }
            
            // Delete logs
            PostLog::where('post_id', $post->id)->delete();
            $post->delete();
        }

        // Remove user-group relationships
        DB::connection('mongodb')->table('user_groups')->where('user_id', $user->id)->delete();

        // Delete payment history
        $user->paymentHistory()->delete();

        // Delete user
        $user->delete();

        return response()->json([
            'message' => 'User deleted successfully'
        ]);
    }

    /**
     * Reset user usage
     */
    public function resetUserUsage($userId)
    {
        $user = User::findOrFail($userId);
        
        $usage = $user->usage;
        $usage['messages_sent_this_month'] = 0;
        $usage['last_reset_date'] = now()->startOfMonth()->toDateTimeString();
        $user->usage = $usage;
        $user->save();

        return response()->json([
            'message' => 'User usage reset successfully',
            'user' => $user
        ]);
    }

    /**
     * Get post details for admin
     */
    public function postDetails($postId)
    {
        $post = ScheduledPost::with(['user:id,first_name,last_name,username'])
                            ->findOrFail($postId);

        // Get groups
        if ($post->group_ids) {
            $post->groups = Group::whereIn('_id', $post->group_ids)->get();
        }

        // Get logs
        $post->logs = $post->logs()->orderBy('sent_at', 'desc')->get();

        return response()->json($post);
    }

    /**
     * Cancel a scheduled post
     */
    public function cancelPost($postId)
    {
        $post = ScheduledPost::findOrFail($postId);
        
        if (!in_array($post->status, ['pending', 'partially_sent'])) {
            return response()->json([
                'error' => 'Cannot cancel this post'
            ], 400);
        }

        $post->update(['status' => 'cancelled']);

        return response()->json([
            'message' => 'Post cancelled successfully',
            'post' => $post
        ]);
    }

    /**
     * Retry failed post
     */
    public function retryPost($postId)
    {
        $post = ScheduledPost::findOrFail($postId);
        
        if ($post->status !== 'failed') {
            return response()->json([
                'error' => 'Only failed posts can be retried'
            ], 400);
        }

        // Reset status and reschedule
        $post->update(['status' => 'pending']);

        // Dispatch jobs for failed messages
        $failedLogs = $post->logs()->where('status', 'failed')->get();
        foreach ($failedLogs as $log) {
            \App\Jobs\SendScheduledPost::dispatch($post, $log->scheduled_time, $log->group_id);
        }

        return response()->json([
            'message' => 'Post retry initiated',
            'post' => $post
        ]);
    }

    /**
     * Get group details for admin
     */
    public function groupDetails($groupId)
    {
        $group = Group::findOrFail($groupId);
        
        // Get admin users
        $adminRelations = DB::connection('mongodb')
            ->table('user_groups')
            ->where('group_id', $group->id)
            ->where('is_admin', true)
            ->get();
        
        $adminUserIds = $adminRelations->pluck('user_id')->toArray();
        $adminUsers = User::whereIn('_id', $adminUserIds)->get();
        
        // Get post statistics
        $postStats = [
            'total_posts' => ScheduledPost::whereJsonContains('group_ids', $group->id)->count(),
            'pending_posts' => ScheduledPost::whereJsonContains('group_ids', $group->id)
                              ->where('status', 'pending')->count(),
            'completed_posts' => ScheduledPost::whereJsonContains('group_ids', $group->id)
                                ->where('status', 'completed')->count(),
            'messages_sent' => PostLog::where('group_id', $group->id)
                              ->where('status', 'sent')->count(),
            'messages_failed' => PostLog::where('group_id', $group->id)
                                ->where('status', 'failed')->count()
        ];

        return response()->json([
            'group' => $group,
            'admin_users' => $adminUsers,
            'post_stats' => $postStats
        ]);
    }

    /**
     * Delete a group
     */
    public function deleteGroup($groupId)
    {
        $group = Group::findOrFail($groupId);
        
        // Check if group has active posts
        $activePosts = ScheduledPost::whereJsonContains('group_ids', $group->id)
                                  ->whereIn('status', ['pending', 'partially_sent'])
                                  ->count();
        
        if ($activePosts > 0) {
            return response()->json([
                'error' => "Cannot delete group with {$activePosts} active posts"
            ], 400);
        }

        // Remove user-group relationships
        DB::connection('mongodb')->table('user_groups')->where('group_id', $group->id)->delete();

        // Delete logs
        PostLog::where('group_id', $group->id)->delete();

        // Delete group
        $group->delete();

        return response()->json([
            'message' => 'Group deleted successfully'
        ]);
    }

    /**
     * Get group users
     */
    public function groupUsers($groupId)
    {
        $group = Group::findOrFail($groupId);
        
        $userRelations = DB::connection('mongodb')
            ->table('user_groups')
            ->where('group_id', $group->id)
            ->get();
        
        $userIds = $userRelations->pluck('user_id')->toArray();
        $users = User::whereIn('_id', $userIds)->get();
        
        // Add relationship info
        $users = $users->map(function($user) use ($userRelations) {
            $relation = $userRelations->where('user_id', $user->id)->first();
            $user->is_admin = $relation->is_admin ?? false;
            $user->added_at = $relation->added_at ?? null;
            $user->last_verified = $relation->last_verified ?? null;
            return $user;
        });

        return response()->json([
            'group' => $group,
            'users' => $users
        ]);
    }

    /**
     * Get error logs
     */
    public function errorLogs(Request $request)
    {
        $query = PostLog::where('status', 'failed')
                       ->with(['post:id,user_id,content', 'group:id,title'])
                       ->orderBy('sent_at', 'desc');

        if ($request->filled('date_from')) {
            $query->where('sent_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->where('sent_at', '<=', $request->date_to);
        }

        if ($request->filled('group_id')) {
            $query->where('group_id', $request->group_id);
        }

        $logs = $query->paginate($request->get('per_page', 50));

        return response()->json($logs);
    }

    /**
     * Clean up old logs
     */
    public function cleanupLogs(Request $request)
    {
        $request->validate([
            'days' => 'required|integer|min:1|max:365'
        ]);

        $cutoffDate = Carbon::now()->subDays($request->days);
        
        $deletedCount = PostLog::where('status', 'failed')
                              ->where('sent_at', '<', $cutoffDate)
                              ->delete();

        return response()->json([
            'message' => "Cleaned up {$deletedCount} old log entries",
            'deleted_count' => $deletedCount
        ]);
    }

    /**
     * Toggle maintenance mode
     */
    public function maintenanceMode(Request $request)
    {
        $request->validate([
            'enabled' => 'required|boolean',
            'message' => 'nullable|string|max:500'
        ]);

        $maintenanceFile = storage_path('framework/maintenance.php');
        
        if ($request->enabled) {
            // Enable maintenance mode
            $data = [
                'time' => time(),
                'message' => $request->message ?? 'System is under maintenance',
                'retry' => 60,
                'allowed' => []
            ];
            
            file_put_contents($maintenanceFile, '<?php return ' . var_export($data, true) . ';');
            
            return response()->json([
                'message' => 'Maintenance mode enabled',
                'maintenance' => $data
            ]);
        } else {
            // Disable maintenance mode
            if (file_exists($maintenanceFile)) {
                unlink($maintenanceFile);
            }
            
            return response()->json([
                'message' => 'Maintenance mode disabled'
            ]);
        }
    }

    /**
     * System cleanup
     */
    public function systemCleanup(Request $request)
    {
        $request->validate([
            'cleanup_logs' => 'boolean',
            'cleanup_media' => 'boolean',
            'cleanup_posts' => 'boolean',
            'days' => 'integer|min:1|max:365'
        ]);

        $days = $request->get('days', 30);
        $cutoffDate = Carbon::now()->subDays($days);
        $results = [];

        if ($request->cleanup_logs) {
            $deletedLogs = PostLog::where('status', 'failed')
                                 ->where('sent_at', '<', $cutoffDate)
                                 ->delete();
            $results['logs_deleted'] = $deletedLogs;
        }

        if ($request->cleanup_posts) {
            $oldPosts = ScheduledPost::where('status', 'completed')
                                   ->where('created_at', '<', $cutoffDate);
            
            $deletedPosts = $oldPosts->count();
            $oldPosts->delete();
            $results['posts_deleted'] = $deletedPosts;
        }

        if ($request->cleanup_media) {
            $orphanedFiles = $this->findOrphanedMediaFiles();
            foreach ($orphanedFiles as $file) {
                \Storage::disk('public')->delete($file);
            }
            $results['media_files_deleted'] = count($orphanedFiles);
        }

        return response()->json([
            'message' => 'System cleanup completed',
            'results' => $results
        ]);
    }

    /**
     * Create system backup
     */
    public function createBackup()
    {
        $timestamp = now()->format('Y-m-d_H-i-s');
        $backupPath = "backups/backup_{$timestamp}";

        // Create backup directory
        \Storage::disk('local')->makeDirectory($backupPath);

        // Export data
        $backup = [
            'timestamp' => $timestamp,
            'version' => config('app.version', '1.0'),
            'users' => User::all(),
            'groups' => Group::all(),
            'posts' => ScheduledPost::all(),
            'currencies' => \App\Models\Currency::all()
        ];

        \Storage::disk('local')->put(
            "{$backupPath}/backup.json",
            json_encode($backup, JSON_PRETTY_PRINT)
        );

        return response()->json([
            'message' => 'Backup created successfully',
            'backup_path' => $backupPath,
            'size' => \Storage::disk('local')->size("{$backupPath}/backup.json")
        ]);
    }

    /**
     * Get system settings
     */
    public function systemSettings()
    {
        return response()->json([
            'app_name' => config('app.name'),
            'app_version' => config('app.version', '1.0'),
            'telegram_bot_username' => config('services.telegram.bot_username'),
            'maintenance_mode' => app()->isDownForMaintenance(),
            'queue_connection' => config('queue.default'),
            'database_connection' => config('database.default'),
            'storage_disk' => config('filesystems.default')
        ]);
    }

    /**
     * Update system settings
     */
    public function updateSystemSettings(Request $request)
    {
        // This would typically update configuration files or database settings
        // Implementation depends on how you want to handle dynamic configuration
        
        return response()->json([
            'message' => 'Settings update not implemented',
            'note' => 'System settings should be updated via environment variables'
        ]);
    }

    /**
     * Statistics methods
     */
    public function statsOverview()
    {
        return $this->dashboard(); // Reuse dashboard method
    }

    public function userStats()
    {
        $stats = [
            'total_users' => User::count(),
            'users_by_plan' => User::select(DB::raw('COALESCE(subscription.plan, "free") as plan'), DB::raw('count(*) as count'))
                ->groupBy(DB::raw('COALESCE(subscription.plan, "free")'))
                ->get(),
            'users_by_month' => User::select(
                DB::raw('YEAR(created_at) as year'),
                DB::raw('MONTH(created_at) as month'),
                DB::raw('count(*) as count')
            )->groupBy(DB::raw('YEAR(created_at)'), DB::raw('MONTH(created_at)'))
             ->orderBy('year')
             ->orderBy('month')
             ->get(),
            'active_users_7d' => User::where('auth_date', '>=', Carbon::now()->subDays(7))->count(),
            'active_users_30d' => User::where('auth_date', '>=', Carbon::now()->subDays(30))->count()
        ];

        return response()->json($stats);
    }

    public function postStats()
    {
        $stats = [
            'total_posts' => ScheduledPost::count(),
            'posts_by_status' => ScheduledPost::select('status', DB::raw('count(*) as count'))
                ->groupBy('status')
                ->get(),
            'posts_by_month' => ScheduledPost::select(
                DB::raw('YEAR(created_at) as year'),
                DB::raw('MONTH(created_at) as month'),
                DB::raw('count(*) as count')
            )->groupBy(DB::raw('YEAR(created_at)'), DB::raw('MONTH(created_at)'))
             ->orderBy('year')
             ->orderBy('month')
             ->get(),
            'messages_sent' => PostLog::where('status', 'sent')->count(),
            'messages_failed' => PostLog::where('status', 'failed')->count()
        ];

        return response()->json($stats);
    }

    public function revenueStats()
    {
        $stats = [
            'total_revenue' => \App\Models\PaymentHistory::where('status', 'succeeded')->sum('amount'),
            'revenue_by_plan' => \App\Models\PaymentHistory::where('status', 'succeeded')
                ->select('plan', DB::raw('sum(amount) as total'))
                ->groupBy('plan')
                ->get(),
            'revenue_by_month' => \App\Models\PaymentHistory::where('status', 'succeeded')
                ->select(
                    DB::raw('YEAR(created_at) as year'),
                    DB::raw('MONTH(created_at) as month'),
                    DB::raw('sum(amount) as total')
                )->groupBy(DB::raw('YEAR(created_at)'), DB::raw('MONTH(created_at)'))
                 ->orderBy('year')
                 ->orderBy('month')
                 ->get()
        ];

        return response()->json($stats);
    }

    public function performanceStats()
    {
        $stats = [
            'avg_response_time' => $this->calculateAverageResponseTime(),
            'error_rate' => $this->calculateErrorRate(),
            'queue_size' => DB::table('jobs')->count(),
            'failed_jobs' => DB::table('failed_jobs')->count(),
            'database_size' => $this->getDatabaseSize(),
            'storage_usage' => $this->getStorageUsage()
        ];

        return response()->json($stats);
    }

    // Helper methods
    private function findOrphanedMediaFiles()
    {
        $mediaFiles = \Storage::disk('public')->files('media');
        $orphanedFiles = [];

        foreach ($mediaFiles as $file) {
            $isReferenced = ScheduledPost::where('content.media', 'elemMatch', [
                'path' => $file
            ])->exists();

            if (!$isReferenced) {
                $orphanedFiles[] = $file;
            }
        }

        return $orphanedFiles;
    }

    private function calculateAverageResponseTime()
    {
        // This would require implementing response time logging
        return 0; // Placeholder
    }

    private function calculateErrorRate()
    {
        $total = PostLog::count();
        if ($total === 0) return 0;
        
        $failed = PostLog::where('status', 'failed')->count();
        return round(($failed / $total) * 100, 2);
    }

    private function getDatabaseSize()
    {
        // MongoDB size calculation would require specific queries
        return 0; // Placeholder
    }

    private function getStorageUsage()
    {
        $totalSize = 0;
        $files = \Storage::disk('public')->allFiles();
        
        foreach ($files as $file) {
            $totalSize += \Storage::disk('public')->size($file);
        }
        
        return $totalSize;
    }

}