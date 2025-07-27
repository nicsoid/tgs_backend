<?php
// app/Console/Commands/FixScheduleTimes.php - Fix schedule times to be processable

namespace App\Console\Commands;

use App\Models\ScheduledPost;
use Illuminate\Console\Command;
use Carbon\Carbon;

class FixScheduleTimes extends Command
{
    protected $signature = 'schedule:fix-times';
    protected $description = 'Fix schedule times to make them processable now';

    public function handle()
    {
        $this->info('🔧 Fixing schedule times...');
        
        $posts = ScheduledPost::where('status', 'pending')->get();
        
        if ($posts->isEmpty()) {
            $this->warn('No pending posts found.');
            return 0;
        }
        
        $this->info("Found {$posts->count()} pending posts");
        
        foreach ($posts as $post) {
            $this->info("Fixing Post {$post->id}...");
            
            $userTimezone = $post->user_timezone ?? 'UTC';
            $now = Carbon::now($userTimezone);
            
            // Create new times: 1 minute ago, now, and 5 minutes from now
            $newTimes = [
                $now->copy()->subMinute()->format('Y-m-d\TH:i'),
                $now->format('Y-m-d\TH:i'),
                $now->copy()->addMinutes(5)->format('Y-m-d\TH:i')
            ];
            
            $this->line("  Old times count: " . count($post->schedule_times ?? []));
            $this->line("  New times: " . implode(', ', $newTimes));
            
            // Update the post
            $post->update([
                'schedule_times' => $newTimes,
                'user_timezone' => $userTimezone
            ]);
            
            $this->info("  ✅ Updated times for processability");
        }
        
        $this->info('✅ All posts updated with processable times');
        
        return 0;
    }
}
