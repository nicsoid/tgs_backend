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
            foreach ($post->schedule_times as $index => $scheduledTime) {
                $scheduledCarbon = Carbon::parse($scheduledTime, $post->user_timezone);
                $scheduledUtc = $scheduledCarbon->utc();
                
                // Check if this time has already been processed
                $alreadyProcessed = $post->logs()
                    ->where('scheduled_time', $scheduledTime)
                    ->exists();

                if (!$alreadyProcessed && $scheduledUtc->isPast()) {
                    // Dispatch job to send the post
                    SendScheduledPost::dispatch($post, $scheduledTime);
                    $count++;
                    
                    $this->info("Dispatched post {$post->id} scheduled for {$scheduledTime}");
                }
            }
        }

        $this->info("Dispatched {$count} posts for sending.");
    }
}