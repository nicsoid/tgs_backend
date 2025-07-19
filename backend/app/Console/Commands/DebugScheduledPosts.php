<?php
// Add a debug command to check timezone conversions
// app/Console/Commands/DebugScheduledPosts.php

namespace App\Console\Commands;

use App\Models\ScheduledPost;
use Illuminate\Console\Command;
use Carbon\Carbon;

class DebugScheduledPosts extends Command
{
    protected $signature = 'posts:debug {--post-id= : Specific post ID to debug}';
    protected $description = 'Debug scheduled posts timezone conversions';

    public function handle()
    {
        $postId = $this->option('post-id');
        
        if ($postId) {
            $posts = ScheduledPost::where('_id', $postId)->get();
        } else {
            $posts = ScheduledPost::where('status', 'pending')->limit(5)->get();
        }

        if ($posts->isEmpty()) {
            $this->info('No posts found');
            return;
        }

        $now = Carbon::now('UTC');
        $this->info("Current UTC time: {$now->format('Y-m-d H:i:s')} UTC");
        $this->info("=".str_repeat("=", 60));

        foreach ($posts as $post) {
            $this->info("Post ID: {$post->id}");
            $this->info("Status: {$post->status}");
            $this->info("User Timezone: {$post->user_timezone}");
            $this->info("Groups: " . count($post->group_ids ?? []));
            
            $scheduleTimesUtc = $post->schedule_times_utc ?? [];
            $scheduleTimes = $post->schedule_times ?? [];
            
            $this->info("\nSchedule Times:");
            foreach ($scheduleTimes as $index => $userTime) {
                $utcTime = $scheduleTimesUtc[$index] ?? 'N/A';
                $utcCarbon = Carbon::parse($utcTime, 'UTC');
                $isPast = $utcCarbon->lte($now);
                $timeUntil = $isPast ? 'PAST' : $now->diffForHumans($utcCarbon);
                
                $this->line("  {$index}: {$userTime} ({$post->user_timezone}) → {$utcTime} UTC [{$timeUntil}]");
            }
            
            $logs = $post->logs()->count();
            $this->info("Logs: {$logs}");
            $this->info("-".str_repeat("-", 60));
        }
    }
}