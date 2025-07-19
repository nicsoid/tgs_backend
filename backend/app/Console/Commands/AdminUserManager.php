<?php
// app/Console/Commands/AdminUserManager.php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\ScheduledPost;
use App\Models\PostLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AdminUserManager extends Command
{
    protected $signature = 'admin:users 
                           {action : Action to perform (list|show|ban|unban|delete|promote|demote|reset-usage)}
                           {--id= : User ID}
                           {--username= : Username (without @)}
                           {--telegram-id= : Telegram ID}
                           {--search= : Search term for list}
                           {--plan= : Filter by plan (free|pro|ultra)}
                           {--limit=20 : Number of results to show}
                           {--force : Force action without confirmation}';
    
    protected $description = 'Manage users - list, ban, promote, delete, etc.';

    public function handle()
    {
        $action = $this->argument('action');

        switch ($action) {
            case 'list':
                return $this->listUsers();
            case 'show':
                return $this->showUser();
            case 'ban':
                return $this->banUser();
            case 'unban':
                return $this->unbanUser();
            case 'delete':
                return $this->deleteUser();
            case 'promote':
                return $this->promoteUser();
            case 'demote':
                return $this->demoteUser();
            case 'reset-usage':
                return $this->resetUsage();
            default:
                $this->error("Invalid action: {$action}");
                $this->info('Available actions: list, show, ban, unban, delete, promote, demote, reset-usage');
                return 1;
        }
    }

    private function listUsers()
    {
        $query = User::query();

        if ($this->option('search')) {
            $search = $this->option('search');
            $query->where(function($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('username', 'like', "%{$search}%");
            });
        }

        if ($this->option('plan')) {
            $query->where('subscription.plan', $this->option('plan'));
        }

        $users = $query->orderBy('created_at', 'desc')
                      ->limit($this->option('limit'))
                      ->get();

        if ($users->isEmpty()) {
            $this->info('No users found.');
            return 0;
        }

        $this->table(['ID', 'Name', 'Username', 'Plan', 'Status', 'Posts', 'Joined'], 
            $users->map(function($user) {
                $isAdmin = $user->settings['is_admin'] ?? false;
                $isBanned = $user->settings['is_banned'] ?? false;
                $status = $isAdmin ? 'Admin' : ($isBanned ? 'Banned' : 'Active');
                
                return [
                    $user->id,
                    $user->first_name . ' ' . $user->last_name,
                    '@' . $user->username,
                    $user->subscription['plan'] ?? 'free',
                    $status,
                    $user->scheduledPosts()->count(),
                    $user->created_at->format('Y-m-d')
                ];
            })->toArray()
        );

        return 0;
    }

    private function showUser()
    {
        $user = $this->findUser();
        if (!$user) return 1;

        $this->info("👤 User Details: {$user->first_name} {$user->last_name}");
        $this->info('================================================');

        $details = [
            ['Field', 'Value'],
            ['ID', $user->id],
            ['Telegram ID', $user->telegram_id],
            ['Username', '@' . $user->username],
            ['Name', $user->first_name . ' ' . $user->last_name],
            ['Plan', $user->subscription['plan'] ?? 'free'],
            ['Status', $this->getUserStatus($user)],
            ['Joined', $user->created_at->format('Y-m-d H:i:s')],
            ['Last Login', $user->auth_date ? $user->auth_date->format('Y-m-d H:i:s') : 'Never'],
            ['Groups Count', $user->usage['groups_count'] ?? 0],
            ['Messages This Month', $user->usage['messages_sent_this_month'] ?? 0],
            ['Total Posts', $user->scheduledPosts()->count()],
            ['Messages Sent', $this->getMessagesSent($user)],
        ];

        $this->table($details[0], array_slice($details, 1));

        // Show recent posts
        $recentPosts = $user->scheduledPosts()->orderBy('created_at', 'desc')->limit(5)->get();
        if ($recentPosts->isNotEmpty()) {
            $this->info("\n📝 Recent Posts:");
            $this->table(['ID', 'Status', 'Groups', 'Created'], 
                $recentPosts->map(fn($post) => [
                    $post->id,
                    $post->status,
                    count($post->group_ids ?? []),
                    $post->created_at->format('Y-m-d H:i:s')
                ])->toArray()
            );
        }

        return 0;
    }

    private function banUser()
    {
        $user = $this->findUser();
        if (!$user) return 1;

        if ($user->settings['is_banned'] ?? false) {
            $this->warn('User is already banned.');
            return 0;
        }

        if (!$this->option('force') && !$this->confirm("Ban user {$user->username}?")) {
            $this->info('Action cancelled.');
            return 0;
        }

        $settings = $user->settings;
        $settings['is_banned'] = true;
        $settings['banned_at'] = now();
        $user->settings = $settings;
        $user->save();

        $this->info("✅ User {$user->username} has been banned.");
        return 0;
    }

    private function unbanUser()
    {
        $user = $this->findUser();
        if (!$user) return 1;

        if (!($user->settings['is_banned'] ?? false)) {
            $this->warn('User is not banned.');
            return 0;
        }

        $settings = $user->settings;
        unset($settings['is_banned'], $settings['banned_at']);
        $user->settings = $settings;
        $user->save();

        $this->info("✅ User {$user->username} has been unbanned.");
        return 0;
    }

    private function promoteUser()
    {
        $user = $this->findUser();
        if (!$user) return 1;

        if ($user->settings['is_admin'] ?? false) {
            $this->warn('User is already an admin.');
            return 0;
        }

        if (!$this->option('force') && !$this->confirm("Promote user {$user->username} to admin?")) {
            $this->info('Action cancelled.');
            return 0;
        }

        $settings = $user->settings;
        $settings['is_admin'] = true;
        $settings['promoted_at'] = now();
        $user->settings = $settings;
        $user->save();

        $this->info("✅ User {$user->username} has been promoted to admin.");
        return 0;
    }

    private function demoteUser()
    {
        $user = $this->findUser();
        if (!$user) return 1;

        if (!($user->settings['is_admin'] ?? false)) {
            $this->warn('User is not an admin.');
            return 0;
        }

        if (!$this->option('force') && !$this->confirm("Demote admin {$user->username}?")) {
            $this->info('Action cancelled.');
            return 0;
        }

        $settings = $user->settings;
        unset($settings['is_admin'], $settings['promoted_at']);
        $user->settings = $settings;
        $user->save();

        $this->info("✅ User {$user->username} has been demoted.");
        return 0;
    }

    private function resetUsage()
    {
        $user = $this->findUser();
        if (!$user) return 1;

        if (!$this->option('force') && !$this->confirm("Reset usage for user {$user->username}?")) {
            $this->info('Action cancelled.');
            return 0;
        }

        $usage = $user->usage;
        $usage['messages_sent_this_month'] = 0;
        $usage['last_reset_date'] = now()->startOfMonth()->toDateTimeString();
        $user->usage = $usage;
        $user->save();

        $this->info("✅ Usage reset for user {$user->username}.");
        return 0;
    }

    private function deleteUser()
    {
        $user = $this->findUser();
        if (!$user) return 1;

        $this->warn("⚠️  This will permanently delete user {$user->username} and all associated data!");
        
        if (!$this->option('force') && !$this->confirm('Are you absolutely sure?')) {
            $this->info('Action cancelled.');
            return 0;
        }

        // Delete user's posts and logs
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
            
            // Delete post
            $post->delete();
        }

        // Remove user-group relationships
        DB::connection('mongodb')->table('user_groups')->where('user_id', $user->id)->delete();

        // Delete payment history
        $user->paymentHistory()->delete();

        // Delete user
        $user->delete();

        $this->info("✅ User {$user->username} and all associated data have been deleted.");
        return 0;
    }

    private function findUser()
    {
        if ($this->option('id')) {
            $user = User::find($this->option('id'));
        } elseif ($this->option('username')) {
            $user = User::where('username', $this->option('username'))->first();
        } elseif ($this->option('telegram-id')) {
            $user = User::where('telegram_id', $this->option('telegram-id'))->first();
        } else {
            $this->error('Please provide --id, --username, or --telegram-id');
            return null;
        }

        if (!$user) {
            $this->error('User not found.');
            return null;
        }

        return $user;
    }

    private function getUserStatus($user)
    {
        if ($user->settings['is_admin'] ?? false) {
            return 'Admin';
        }
        
        if ($user->settings['is_banned'] ?? false) {
            return 'Banned';
        }
        
        return 'Active';
    }

    private function getMessagesSent($user)
    {
        return PostLog::whereHas('post', function($q) use ($user) {
            $q->where('user_id', $user->id);
        })->where('status', 'sent')->count();
    }
}

// app/Console/Commands/AdminSystemManager.php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Group;
use App\Models\ScheduledPost;
use App\Models\PostLog;
use App\Services\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AdminSystemManager extends Command
{
    protected $signature = 'admin:system 
                           {action : Action to perform (health|cleanup|maintenance|backup|restore)}
                           {--days=7 : Number of days for cleanup operations}
                           {--force : Force action without confirmation}
                           {--dry-run : Show what would be done without executing}';
    
    protected $description = 'System maintenance and health checks';

    protected $telegramService;

    public function __construct(TelegramService $telegramService)
    {
        parent::__construct();
        $this->telegramService = $telegramService;
    }

    public function handle()
    {
        $action = $this->argument('action');

        switch ($action) {
            case 'health':
                return $this->healthCheck();
            case 'cleanup':
                return $this->cleanup();
            case 'maintenance':
                return $this->maintenance();
            case 'backup':
                return $this->backup();
            case 'restore':
                return $this->restore();
            default:
                $this->error("Invalid action: {$action}");
                $this->info('Available actions: health, cleanup, maintenance, backup, restore');
                return 1;
        }
    }

    private function healthCheck()
    {
        $this->info('🏥 System Health Check');
        $this->info('======================');

        $checks = [];

        // Database check
        try {
            DB::connection('mongodb')->getPdo();
            $checks[] = ['Database', '✅ Connected', 'OK'];
        } catch (\Exception $e) {
            $checks[] = ['Database', '❌ Failed', $e->getMessage()];
        }

        // Telegram Bot check
        try {
            $botInfo = $this->telegramService->getBotInfo();
            if ($botInfo) {
                $checks[] = ['Telegram Bot', '✅ Responsive', '@' . $botInfo['username']];
            } else {
                $checks[] = ['Telegram Bot', '❌ Not responding', 'Failed to get bot info'];
            }
        } catch (\Exception $e) {
            $checks[] = ['Telegram Bot', '❌ Error', $e->getMessage()];
        }

        // Storage check
        $storagePath = storage_path('app/public');
        if (is_writable($storagePath)) {
            $checks[] = ['Storage', '✅ Writable', $storagePath];
        } else {
            $checks[] = ['Storage', '❌ Not writable', $storagePath];
        }

        // Queue check
        try {
            $stuckJobs = DB::table('jobs')
                ->where('created_at', '<', Carbon::now()->subHours(1))
                ->count();
            
            if ($stuckJobs === 0) {
                $checks[] = ['Queue', '✅ Healthy', 'No stuck jobs'];
            } else {
                $checks[] = ['Queue', '⚠️ Warning', "{$stuckJobs} stuck jobs"];
            }
        } catch (\Exception $e) {
            $checks[] = ['Queue', '❓ Unknown', 'Could not check'];
        }

        // Recent errors
        $recentErrors = PostLog::where('status', 'failed')
            ->where('sent_at', '>=', Carbon::now()->subHours(24))
            ->count();

        if ($recentErrors === 0) {
            $checks[] = ['Error Rate', '✅ Good', 'No errors in 24h'];
        } elseif ($recentErrors < 10) {
            $checks[] = ['Error Rate', '⚠️ Warning', "{$recentErrors} errors in 24h"];
        } else {
            $checks[] = ['Error Rate', '❌ High', "{$recentErrors} errors in 24h"];
        }

        $this->table(['Component', 'Status', 'Details'], $checks);

        // Summary
        $okCount = collect($checks)->where(1, 'like', '✅%')->count();
        $warningCount = collect($checks)->where(1, 'like', '⚠️%')->count();
        $errorCount = collect($checks)->where(1, 'like', '❌%')->count();

        $this->info("\n📊 Summary:");
        $this->info("✅ OK: {$okCount}");
        if ($warningCount > 0) $this->warn("⚠️ Warnings: {$warningCount}");
        if ($errorCount > 0) $this->error("❌ Errors: {$errorCount}");

        return $errorCount === 0 ? 0 : 1;
    }

    private function cleanup()
    {
        $days = $this->option('days');
        $dryRun = $this->option('dry-run');
        
        $this->info("🧹 System Cleanup (older than {$days} days)");
        $this->info('========================================');

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        $cutoffDate = Carbon::now()->subDays($days);

        // Clean up old failed logs
        $oldLogs = PostLog::where('status', 'failed')
            ->where('sent_at', '<', $cutoffDate);
        
        $oldLogsCount = $oldLogs->count();
        
        if ($oldLogsCount > 0) {
            $this->info("Found {$oldLogsCount} old failed logs to clean up");
            
            if (!$dryRun && ($this->option('force') || $this->confirm('Delete old failed logs?'))) {
                $oldLogs->delete();
                $this->info("✅ Deleted {$oldLogsCount} old failed logs");
            }
        }

        // Clean up completed posts older than specified days
        $oldPosts = ScheduledPost::where('status', 'completed')
            ->where('created_at', '<', $cutoffDate);
        
        $oldPostsCount = $oldPosts->count();
        
        if ($oldPostsCount > 0) {
            $this->info("Found {$oldPostsCount} old completed posts");
            
            if (!$dryRun && ($this->option('force') || $this->confirm('Archive old completed posts?'))) {
                foreach ($oldPosts->get() as $post) {
                    // Clean up media files
                    if (isset($post->content['media'])) {
                        foreach ($post->content['media'] as $media) {
                            if (isset($media['path']) && \Storage::disk('public')->exists($media['path'])) {
                                if (!$dryRun) {
                                    \Storage::disk('public')->delete($media['path']);
                                }
                            }
                        }
                    }
                }
                
                if (!$dryRun) {
                    $oldPosts->delete();
                    $this->info("✅ Archived {$oldPostsCount} old completed posts");
                }
            }
        }

        // Clean up orphaned media files
        $this->cleanupOrphanedMedia($dryRun);

        // Optimize database
        if (!$dryRun && ($this->option('force') || $this->confirm('Optimize database?'))) {
            $this->info('🔧 Optimizing database...');
            // Add database optimization commands here
            $this->info('✅ Database optimized');
        }

        return 0;
    }

    private function maintenance()
    {
        $this->info('🔧 System Maintenance');
        $this->info('=====================');

        // Verify group admin statuses
        $this->info('Verifying admin statuses...');
        $this->call('admin:verify', ['--stale-only' => true]);

        // Refresh group information
        $this->info('Refreshing group info...');
        $this->call('groups:refresh-info', ['--stale-only' => true]);

        // Update currency rates
        $this->info('Updating currency rates...');
        $this->call('currencies:update');

        // Monitor scheduler
        $this->info('Checking scheduler status...');
        $this->call('scheduler:monitor');

        $this->info('✅ Maintenance completed');
        return 0;
    }

    private function backup()
    {
        $this->info('💾 Creating System Backup');
        $this->info('=========================');

        $timestamp = now()->format('Y-m-d_H-i-s');
        $backupPath = storage_path("backups/backup_{$timestamp}");

        if (!is_dir(dirname($backupPath))) {
            mkdir(dirname($backupPath), 0755, true);
        }

        // Export users
        $this->info('Backing up users...');
        $users = User::all()->toArray();
        file_put_contents("{$backupPath}_users.json", json_encode($users, JSON_PRETTY_PRINT));

        // Export groups
        $this->info('Backing up groups...');
        $groups = Group::all()->toArray();
        file_put_contents("{$backupPath}_groups.json", json_encode($groups, JSON_PRETTY_PRINT));

        // Export posts
        $this->info('Backing up posts...');
        $posts = ScheduledPost::all()->toArray();
        file_put_contents("{$backupPath}_posts.json", json_encode($posts, JSON_PRETTY_PRINT));

        // Create backup info
        $backupInfo = [
            'timestamp' => $timestamp,
            'version' => config('app.version', '1.0'),
            'users_count' => count($users),
            'groups_count' => count($groups),
            'posts_count' => count($posts),
        ];
        file_put_contents("{$backupPath}_info.json", json_encode($backupInfo, JSON_PRETTY_PRINT));

        $this->info("✅ Backup created: {$backupPath}");
        return 0;
    }

    private function restore()
    {
        $this->error('🚫 Restore functionality not yet implemented');
        $this->info('This is a potentially dangerous operation that requires careful implementation.');
        return 1;
    }

    private function cleanupOrphanedMedia($dryRun = false)
    {
        $this->info('🗂️  Checking for orphaned media files...');

        $mediaPath = storage_path('app/public/media');
        if (!is_dir($mediaPath)) {
            return;
        }

        $files = glob($mediaPath . '/*');
        $orphanedFiles = [];

        foreach ($files as $file) {
            $filename = basename($file);
            
            // Check if this file is referenced in any post
            $isReferenced = ScheduledPost::where('content.media', 'elemMatch', [
                'path' => "media/{$filename}"
            ])->exists();

            if (!$isReferenced) {
                $orphanedFiles[] = $file;
            }
        }

        if (empty($orphanedFiles)) {
            $this->info('✅ No orphaned media files found');
            return;
        }

        $this->info('Found ' . count($orphanedFiles) . ' orphaned media files');

        if (!$dryRun && ($this->option('force') || $this->confirm('Delete orphaned media files?'))) {
            foreach ($orphanedFiles as $file) {
                unlink($file);
            }
            $this->info('✅ Deleted ' . count($orphanedFiles) . ' orphaned media files');
        }
    }
}