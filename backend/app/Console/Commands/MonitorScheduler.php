<?php
// app/Console/Commands/MonitorScheduler.php

namespace App\Console\Commands;

use App\Models\ScheduledPost;
use App\Models\PostLog;
use Illuminate\Console\Command;
use Carbon\Carbon;

class MonitorScheduler extends Command
{
    protected $signature = 'scheduler:monitor';
    protected $description = 'Monitor scheduler performance and status';
    
    public function handle()
    {
        $this->info('Scheduler Monitor Dashboard');
        $this->info('==========================');
        
        // Pending posts
        $pendingCount = ScheduledPost::where('status', 'pending')
            ->whereHas('schedule_times_utc', function ($query) {
                $query->where('schedule_times_utc', '<=', Carbon::now()->addMinutes(60));
            })
            ->count();
            
        $this->info("Posts due in next hour: {$pendingCount}");
        
        // Recent failures
        $recentFailures = PostLog::where('status', 'failed')
            ->where('created_at', '>=', Carbon::now()->subHours(24))
            ->count();
            
        $this->warn("Failed sends (24h): {$recentFailures}");
        
        // Processing rate
        $processedToday = PostLog::where('status', 'sent')
            ->where('sent_at', '>=', Carbon::today())
            ->count();
            
        $this->info("Messages sent today: {$processedToday}");
        
        // Next scheduled posts
        $this->info("\nNext 5 scheduled posts:");
        $nextPosts = ScheduledPost::whereIn('status', ['pending', 'partially_sent'])
            ->with(['group', 'user'])
            ->get()
            ->flatMap(function ($post) {
                return collect($post->schedule_times_utc)->map(function ($time) use ($post) {
                    return [
                        'time' => Carbon::parse($time),
                        'post' => $post,
                        'time_local' => Carbon::parse($time)->timezone($post->user_timezone)
                    ];
                });
            })
            ->filter(function ($item) {
                return $item['time']->isFuture();
            })
            ->sortBy('time')
            ->take(5);
            
        foreach ($nextPosts as $item) {
            $this->line(sprintf(
                "- %s | %s | %s",
                $item['time_local']->format('Y-m-d H:i:s'),
                $item['post']->group->title,
                $item['post']->user->username
            ));
        }
        
        // Failed posts detail
        if ($recentFailures > 0) {
            $this->info("\nRecent failures:");
            $failures = PostLog::where('status', 'failed')
                ->where('created_at', '>=', Carbon::now()->subHours(24))
                ->with('post.group')
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->get();
                
            foreach ($failures as $failure) {
                $this->error(sprintf(
                    "- %s | %s | %s",
                    $failure->created_at->format('Y-m-d H:i:s'),
                    $failure->post->group->title ?? 'Unknown',
                    substr($failure->error_message, 0, 50)
                ));
            }
        }
    }
}