<?php
// app/Console/Commands/DiagnoseScheduling.php - Detailed scheduling diagnosis

namespace App\Console\Commands;

use App\Models\ScheduledPost;
use App\Models\PostLog;
use App\Models\Group;
use Illuminate\Console\Command;
use Carbon\Carbon;

class DiagnoseScheduling extends Command
{
    protected $signature = 'schedule:diagnose';
    protected $description = 'Diagnose scheduling issues in detail';

    public function handle()
    {
        $this->info('🔍 DETAILED SCHEDULING DIAGNOSIS');
        $this->info('================================');
        
        $now = Carbon::now('UTC');
        $this->info("Current UTC time: {$now->format('Y-m-d H:i:s T')}");
        $this->info("Current local time: " . Carbon::now()->format('Y-m-d H:i:s T'));
        $this->info("Laravel timezone: " . config('app.timezone'));
        $this->info("PHP timezone: " . date_default_timezone_get());
        $this->line('');

        // Get all posts
        $posts = ScheduledPost::all();
        $this->info("Total posts in database: {$posts->count()}");
        
        $pendingPosts = $posts->where('status', 'pending');
        $this->info("Pending posts: {$pendingPosts->count()}");
        $this->line('');

        if ($pendingPosts->isEmpty()) {
            $this->error('❌ NO PENDING POSTS FOUND!');
            $this->info('This explains why no jobs are dispatched.');
            $this->info('Create a test post with near-future times.');
            return;
        }

        foreach ($pendingPosts as $post) {
            $this->info("=== POST {$post->id} ===");
            $this->info("Status: {$post->status}");
            $this->info("User timezone: " . ($post->user_timezone ?? 'NONE'));
            
            $groupIds = $post->group_ids ?? [];
            $this->info("Groups: " . count($groupIds));
            
            // Validate groups exist
            $validGroups = 0;
            foreach ($groupIds as $groupId) {
                if (Group::find($groupId)) {
                    $validGroups++;
                }
            }
            $this->info("Valid groups: {$validGroups}/" . count($groupIds));
            
            $userTimes = $post->schedule_times ?? [];
            $utcTimes = $post->schedule_times_utc ?? [];
            
            $this->info("User schedule times: " . count($userTimes));
            $this->info("UTC schedule times: " . count($utcTimes));
            
            if (empty($userTimes) || empty($utcTimes)) {
                $this->error('❌ CRITICAL: Missing schedule times arrays!');
                continue;
            }
            
            // Analyze each time
            $processableNow = 0;
            $futureCount = 0;
            $pastCount = 0;
            
            foreach ($utcTimes as $index => $timeUtc) {
                $userTime = $userTimes[$index] ?? 'N/A';
                
                try {
                    $scheduledUtc = Carbon::parse($timeUtc, 'UTC');
                    $minutesFromNow = $now->diffInMinutes($scheduledUtc, false);
                    
                    if ($scheduledUtc->isFuture()) {
                        $futureCount++;
                    } else {
                        $pastCount++;
                    }
                    
                    // Check ProcessScheduledPosts logic
                    $pastCutoff = $now->copy()->subHours(1);
                    $futureCutoff = $now->copy()->addMinutes(2);
                    
                    if ($scheduledUtc->gte($pastCutoff) && $scheduledUtc->lte($futureCutoff)) {
                        $processableNow++;
                        $this->info("  ✅ PROCESSABLE: {$timeUtc} (user: {$userTime}) - {$minutesFromNow} min");
                    } else {
                        $this->line("  ⏰ WAITING: {$timeUtc} (user: {$userTime}) - {$minutesFromNow} min");
                    }
                    
                } catch (\Exception $e) {
                    $this->error("  ❌ ERROR: {$timeUtc} - " . $e->getMessage());
                }
            }
            
            $this->info("Summary: {$futureCount} future, {$pastCount} past, {$processableNow} processable now");
            
            // Check if already sent
            $sentCount = PostLog::where('post_id', $post->id)->where('status', 'sent')->count();
            $this->info("Already sent: {$sentCount}");
            
            $this->line('---');
        }
        
        // Overall diagnosis
        $this->line('');
        $this->info('🎯 DIAGNOSIS SUMMARY');
        $this->info('===================');
        
        $totalProcessable = 0;
        foreach ($pendingPosts as $post) {
            $now = Carbon::now('UTC');
            $pastCutoff = $now->copy()->subHours(1);
            $futureCutoff = $now->copy()->addMinutes(2);
            
            foreach ($post->schedule_times_utc ?? [] as $timeUtc) {
                try {
                    $scheduledUtc = Carbon::parse($timeUtc, 'UTC');
                    if ($scheduledUtc->gte($pastCutoff) && $scheduledUtc->lte($futureCutoff)) {
                        $totalProcessable++;
                    }
                } catch (\Exception $e) {
                    // Skip invalid times
                }
            }
        }
        
        if ($totalProcessable === 0) {
            $this->error('❌ NO PROCESSABLE TIMES FOUND!');
            $this->info('Possible causes:');
            $this->info('1. All times are too far in the future (>2 minutes)');
            $this->info('2. All times are too old (>1 hour ago)');
            $this->info('3. Timezone conversion is wrong');
            $this->info('4. Schedule times are malformed');
        } else {
            $this->info("✅ Found {$totalProcessable} processable times");
            $this->info('Jobs should be dispatched on next run!');
        }
        
        return 0;
    }
}
