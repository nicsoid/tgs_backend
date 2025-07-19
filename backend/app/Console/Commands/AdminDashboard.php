<?php
// app/Console/Commands/AdminDashboard.php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Group;
use App\Models\ScheduledPost;
use App\Models\PostLog;
use Illuminate\Console\Command;
use Carbon\Carbon;

class AdminDashboard extends Command
{
    protected $signature = 'admin:dashboard 
                           {--json : Output as JSON}
                           {--users : Show user statistics}
                           {--posts : Show post statistics}
                           {--groups : Show group statistics}
                           {--errors : Show recent errors}';
    
    protected $description = 'Display admin dashboard with system statistics';

    public function handle()
    {
        $this->info('🔧 Telegram Scheduler - Admin Dashboard');
        $this->info('======================================');

        if ($this->option('users')) {
            $this->showUserStats();
        } elseif ($this->option('posts')) {
            $this->showPostStats();
        } elseif ($this->option('groups')) {
            $this->showGroupStats();
        } elseif ($this->option('errors')) {
            $this->showRecentErrors();
        } else {
            $this->showOverview();
        }

        return 0;
    }

    private function showOverview()
    {
        // Users
        $totalUsers = User::count();
        $activeUsers = User::where('auth_date', '>=', Carbon::now()->subDays(7))->count();
        $newUsers = User::where('created_at', '>=', Carbon::now()->subDays(7))->count();

        // Posts
        $totalPosts = ScheduledPost::count();
        $pendingPosts = ScheduledPost::where('status', 'pending')->count();
        $completedPosts = ScheduledPost::where('status', 'completed')->count();

        // Groups
        $totalGroups = Group::count();
        $totalMembers = Group::sum('member_count') ?: 0; // Handle null case

        // Messages - Fixed calculation
        $messagesSent = PostLog::where('status', 'sent')->count();
        $messagesFailed = PostLog::where('status', 'failed')->count();
        $totalMessages = $messagesSent + $messagesFailed;
        
        // Fix division by zero error
        $successRate = $totalMessages > 0 ? round(($messagesSent / $totalMessages) * 100, 2) : 100;

        $this->table(['Metric', 'Value'], [
            ['Total Users', number_format($totalUsers)],
            ['Active Users (7d)', number_format($activeUsers)],
            ['New Users (7d)', number_format($newUsers)],
            ['Total Posts', number_format($totalPosts)],
            ['Pending Posts', number_format($pendingPosts)],
            ['Completed Posts', number_format($completedPosts)],
            ['Total Groups', number_format($totalGroups)],
            ['Total Members', number_format($totalMembers)],
            ['Messages Sent', number_format($messagesSent)],
            ['Messages Failed', number_format($messagesFailed)],
            ['Success Rate', $successRate . '%'],
        ]);
    }

    private function showUserStats()
    {
        $this->info('👥 User Statistics');
        $this->info('==================');

        // Users by plan
        $usersByPlan = User::select(\DB::raw('COALESCE(subscription.plan, "free") as plan'), \DB::raw('count(*) as count'))
            ->groupBy(\DB::raw('COALESCE(subscription.plan, "free")'))
            ->get();

        $this->table(['Plan', 'Users'], 
            $usersByPlan->map(fn($row) => [$row->plan, $row->count])->toArray()
        );

        // Recent signups
        $recentUsers = User::orderBy('created_at', 'desc')->limit(10)->get();
        
        $this->info("\n📈 Recent Signups:");
        $this->table(['Name', 'Username', 'Joined'], 
            $recentUsers->map(fn($user) => [
                $user->first_name . ' ' . $user->last_name,
                '@' . $user->username,
                $user->created_at->format('Y-m-d H:i:s')
            ])->toArray()
        );
    }

    private function showPostStats()
    {
        $this->info('📝 Post Statistics');
        $this->info('==================');

        // Simple count by status for MongoDB
        $statuses = ['pending', 'completed', 'failed', 'partially_sent'];
        $statusCounts = [];
        
        foreach ($statuses as $status) {
            $count = ScheduledPost::where('status', $status)->count();
            if ($count > 0) {
                $statusCounts[] = [$status, $count];
            }
        }
        
        if (empty($statusCounts)) {
            $statusCounts = [['No posts yet', '0']];
        }

        $this->table(['Status', 'Count'], $statusCounts);

        // Recent posts
        $recentPosts = ScheduledPost::with('user')->orderBy('created_at', 'desc')->limit(10)->get();
        
        $this->info("\n📋 Recent Posts:");
        
        if ($recentPosts->isEmpty()) {
            $this->info('No posts found.');
            return;
        }

        $this->table(['User', 'Status', 'Groups', 'Created'], 
            $recentPosts->map(fn($post) => [
                $post->user->username ?? 'Unknown',
                $post->status,
                count($post->group_ids ?? []),
                $post->created_at->format('Y-m-d H:i:s')
            ])->toArray()
        );
    }

    private function showGroupStats()
    {
        $this->info('👥 Group Statistics');
        $this->info('===================');

        $totalGroups = Group::count();
        $totalMembers = Group::sum('member_count');
        $avgMembers = Group::avg('member_count');

        $this->table(['Metric', 'Value'], [
            ['Total Groups', number_format($totalGroups)],
            ['Total Members', number_format($totalMembers)],
            ['Average Members', number_format($avgMembers, 0)],
        ]);

        // Top groups by member count
        $topGroups = Group::orderBy('member_count', 'desc')->limit(10)->get();
        
        $this->info("\n🏆 Top Groups by Members:");
        $this->table(['Group', 'Username', 'Members'], 
            $topGroups->map(fn($group) => [
                $group->title,
                $group->username ? '@' . $group->username : 'N/A',
                number_format($group->member_count)
            ])->toArray()
        );
    }

    private function showRecentErrors()
    {
        $this->info('❌ Recent Errors (24h)');
        $this->info('======================');

        $errors = PostLog::where('status', 'failed')
            ->where('sent_at', '>=', Carbon::now()->subHours(24))
            ->with(['post.user', 'group'])
            ->orderBy('sent_at', 'desc')
            ->limit(20)
            ->get();

        if ($errors->isEmpty()) {
            $this->info('✅ No errors in the last 24 hours!');
            return;
        }

        $this->table(['Time', 'User', 'Group', 'Error'], 
            $errors->map(fn($error) => [
                $error->sent_at->format('H:i:s'),
                $error->post->user->username ?? 'Unknown',
                $error->group->title ?? 'Unknown',
                substr($error->error_message, 0, 50) . '...'
            ])->toArray()
        );

        $this->warn("Total errors in last 24h: " . $errors->count());
    }
}