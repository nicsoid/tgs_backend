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

        foreach ($posts as $post) {
            $groupIds = $post->group_ids ?? [];
            
            if (empty($groupIds)) {
                $this->warn("Post {$post->id} has no groups assigned, skipping.");
                continue;
            }

            foreach ($post->schedule_times as $scheduledTime) {
                $scheduledCarbon = Carbon::parse($scheduledTime, $post->user_timezone);
                $scheduledUtc = $scheduledCarbon->utc();
                
                // Only process if time has passed
                if ($scheduledUtc->isPast()) {
                    foreach ($groupIds as $groupId) {
                        // Check if this time/group combination has already been processed
                        $alreadyProcessed = $post->logs()
                            ->where('scheduled_time', $scheduledTime)
                            ->where('group_id', $groupId)
                            ->exists();

                        if (!$alreadyProcessed) {
                            // Dispatch job to send the post to this specific group
                            SendScheduledPost::dispatch($post, $scheduledTime, $groupId);
                            $count++;
                            
                            $this->info("Dispatched post {$post->id} to group {$groupId} scheduled for {$scheduledTime}");
                        }
                    }
                }
            }
        }

        $this->info("Dispatched {$count} individual messages for sending.");
    }
}