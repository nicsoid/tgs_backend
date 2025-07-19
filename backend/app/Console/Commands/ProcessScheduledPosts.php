<?php
// app/Console/Commands/ProcessScheduledPosts.php

namespace App\Console\Commands;

use App\Models\ScheduledPost;
use App\Jobs\SendScheduledPost;
use Illuminate\Console\Command;
use Carbon\Carbon;

class ProcessScheduledPosts extends Command
{
    protected $signature = 'posts:process-scheduled';
    protected $description = 'Process scheduled posts that are due to be sent';

    public function handle()
    {
        $this->info('Processing scheduled posts...');

        $posts = ScheduledPost::whereIn('status', ['pending', 'partially_sent'])
            ->get();

        $count = 0;
        $now = Carbon::now('UTC'); // Current time in UTC

        foreach ($posts as $post) {
            $groupIds = $post->group_ids ?? [];
            
            if (empty($groupIds)) {
                $this->warn("Post {$post->id} has no groups assigned, skipping.");
                continue;
            }

            // Use UTC times directly - they're already stored in UTC
            $scheduleTimesUtc = $post->schedule_times_utc ?? [];
            
            if (empty($scheduleTimesUtc)) {
                $this->warn("Post {$post->id} has no UTC schedule times, skipping.");
                continue;
            }

            foreach ($scheduleTimesUtc as $index => $scheduledTimeUtc) {
                try {
                    // Parse UTC time directly
                    $scheduledUtc = Carbon::parse($scheduledTimeUtc, 'UTC');
                    
                    // Check if this time has passed (with 1 minute tolerance for processing delay)
                    if ($scheduledUtc->lte($now->copy()->addMinute())) {
                        
                        // Get the original user timezone time for logging
                        $originalScheduleTime = $post->schedule_times[$index] ?? $scheduledTimeUtc;
                        
                        foreach ($groupIds as $groupId) {
                            // Check if this time/group combination has already been processed
                            $alreadyProcessed = $post->logs()
                                ->where('scheduled_time', $originalScheduleTime)
                                ->where('group_id', $groupId)
                                ->exists();

                            if (!$alreadyProcessed) {
                                // Dispatch job to send the post to this specific group
                                SendScheduledPost::dispatch($post, $originalScheduleTime, $groupId);
                                $count++;
                                
                                $this->info("Dispatched post {$post->id} to group {$groupId} scheduled for {$originalScheduleTime} (UTC: {$scheduledTimeUtc})");
                            } else {
                                $this->info("Already processed: post {$post->id} to group {$groupId} at {$originalScheduleTime}");
                            }
                        }
                    } else {
                        $timeUntil = $now->diffInMinutes($scheduledUtc);
                        $this->info("Post {$post->id} scheduled for {$scheduledTimeUtc} UTC (in {$timeUntil} minutes)");
                    }
                } catch (\Exception $e) {
                    $this->error("Error processing time {$scheduledTimeUtc} for post {$post->id}: " . $e->getMessage());
                    continue;
                }
            }

            // Update post status after processing all times
            $this->updatePostStatus($post);
        }

        $this->info("Dispatched {$count} individual messages for sending.");
        
        if ($count === 0) {
            $this->info("No posts are due for sending at this time.");
        }
    }

    private function updatePostStatus(ScheduledPost $post)
    {
        $totalScheduled = $post->total_scheduled ?? 0;
        $sentCount = $post->logs()->where('status', 'sent')->count();
        $failedCount = $post->logs()->where('status', 'failed')->count();
        $totalProcessed = $sentCount + $failedCount;

        if ($totalProcessed >= $totalScheduled && $sentCount > 0) {
            $post->update(['status' => 'completed']);
        } elseif ($sentCount > 0) {
            $post->update(['status' => 'partially_sent']);
        } elseif ($failedCount > 0 && $sentCount === 0) {
            $post->update(['status' => 'failed']);
        }
        // Keep as 'pending' if nothing has been processed yet
    }
}