<?php
// app/Console/Commands/SendDueMessages.php - Simple UTC time-based scheduler

namespace App\Console\Commands;

use App\Models\ScheduledPost;
use App\Models\Group;
use App\Models\PostLog;
use App\Services\TelegramService;
use Illuminate\Console\Command;
use Carbon\Carbon;

class SendDueMessages extends Command
{
    protected $signature = 'messages:send-due 
                           {--dry-run : Show what would be sent}
                           {--debug : Show detailed output}
                           {--window=2 : Minutes window around current time}';
    
    protected $description = 'Send messages that are due based on UTC time matching';

    protected $telegramService;

    public function __construct(TelegramService $telegramService)
    {
        parent::__construct();
        $this->telegramService = $telegramService;
    }

    public function handle()
    {
        $dryRun = $this->option('dry-run');
        $debug = $this->option('debug');
        $window = (int) $this->option('window');
        
        $this->info('🕐 Simple UTC Time-Based Message Sender');
        $this->info('======================================');
        
        if ($dryRun) {
            $this->warn('DRY RUN MODE - No messages will be sent');
        }
        
        $nowUtc = Carbon::now('UTC');
        $this->info("Current UTC: {$nowUtc->format('Y-m-d H:i:s')}");
        
        // Define time window (current minute ± window minutes)
        $startTime = $nowUtc->copy()->subMinutes($window);
        $endTime = $nowUtc->copy()->addMinutes($window);
        
        $this->info("Looking for messages between: {$startTime->format('H:i')} and {$endTime->format('H:i')}");
        
        // Get ALL posts (no status filtering)
        $posts = ScheduledPost::all();
        
        if ($posts->isEmpty()) {
            $this->warn('No posts found');
            return 0;
        }
        
        $this->info("Found {$posts->count()} posts to check");
        
        $totalSent = 0;
        $totalSkipped = 0;
        $totalFailed = 0;
        
        foreach ($posts as $post) {
            if ($debug) {
                $this->info("Checking Post {$post->id}...");
            }
            
            $userTimes = $post->schedule_times ?? [];
            $utcTimes = $post->schedule_times_utc ?? [];
            $groupIds = $post->group_ids ?? [];
            
            if (empty($groupIds)) {
                if ($debug) $this->warn("  No groups, skipping");
                continue;
            }
            
            // Fix missing UTC times
            if (empty($utcTimes) && !empty($userTimes) && $post->user_timezone) {
                if ($debug) $this->info("  Generating missing UTC times...");
                $utcTimes = $this->generateUtcTimes($userTimes, $post->user_timezone);
                $post->schedule_times_utc = $utcTimes;
                $post->save();
            }
            
            if (empty($utcTimes)) {
                if ($debug) $this->warn("  No schedule times, skipping");
                continue;
            }
            
            // Check each time
            foreach ($utcTimes as $index => $utcTimeString) {
                $userTime = $userTimes[$index] ?? $utcTimeString;
                
                try {
                    $scheduledUtc = Carbon::parse($utcTimeString, 'UTC');
                    
                    // Simple time matching: is this time within our window?
                    $isDue = $scheduledUtc->between($startTime, $endTime);
                    
                    if ($debug) {
                        $diffMinutes = $nowUtc->diffInMinutes($scheduledUtc, false);
                        $this->line("  Time {$index}: {$userTime} → {$utcTimeString}");
                        $this->line("    Difference: {$diffMinutes} minutes");
                        $this->line("    Due now: " . ($isDue ? 'YES ✅' : 'NO ❌'));
                    }
                    
                    if ($isDue) {
                        foreach ($groupIds as $groupId) {
                            // Check if already sent
                            if ($this->isAlreadySent($post->id, $groupId, $userTime)) {
                                if ($debug) $this->line("    Already sent to group {$groupId}");
                                $totalSkipped++;
                                continue;
                            }
                            
                            $group = Group::find($groupId);
                            if (!$group) {
                                if ($debug) $this->error("    Group {$groupId} not found");
                                continue;
                            }
                            
                            if ($debug) {
                                $this->line("    Sending to: {$group->title}");
                            }
                            
                            if (!$dryRun) {
                                $result = $this->sendMessage($post, $group, $userTime);
                                if ($result['success']) {
                                    $totalSent++;
                                    $this->info("    ✅ Sent to {$group->title}");
                                } else {
                                    $totalFailed++;
                                    $this->error("    ❌ Failed to {$group->title}: {$result['error']}");
                                }
                            } else {
                                $this->line("    [DRY RUN] Would send to {$group->title}");
                                $totalSent++;
                            }
                        }
                    }
                    
                } catch (\Exception $e) {
                    $this->error("  Error processing time {$utcTimeString}: " . $e->getMessage());
                }
            }
        }
        
        $this->info('');
        $this->info('📊 RESULTS:');
        $this->info("Messages sent: {$totalSent}");
        $this->info("Messages skipped (already sent): {$totalSkipped}");
        
        if ($totalFailed > 0) {
            $this->error("Messages failed: {$totalFailed}");
        }
        
        if ($totalSent > 0 && !$dryRun) {
            $this->info('✅ Check your Telegram groups for new messages!');
        }
        
        return 0;
    }
    
    private function generateUtcTimes($userTimes, $userTimezone)
    {
        $utcTimes = [];
        
        foreach ($userTimes as $userTime) {
            try {
                $userCarbon = Carbon::parse($userTime, $userTimezone);
                $utcTimes[] = $userCarbon->utc()->format('Y-m-d H:i:s');
            } catch (\Exception $e) {
                $this->error("Error converting time {$userTime}: " . $e->getMessage());
                $utcTimes[] = $userTime; // Fallback
            }
        }
        
        return $utcTimes;
    }
    
    private function isAlreadySent($postId, $groupId, $scheduledTime)
    {
        return PostLog::where('post_id', $postId)
            ->where('group_id', $groupId)
            ->where('scheduled_time', $scheduledTime)
            ->where('status', 'sent')
            ->exists();
    }
    
    private function sendMessage($post, $group, $scheduledTime)
    {
        try {
            $text = $post->content['text'] ?? 'Scheduled message';
            $media = $post->content['media'] ?? [];
            
            $result = $this->telegramService->sendMessage(
                $group->telegram_id,
                $text,
                $media
            );
            
            if ($result && $result['ok']) {
                // Log success
                PostLog::create([
                    'post_id' => $post->id,
                    'group_id' => $group->id,
                    'scheduled_time' => $scheduledTime,
                    'sent_at' => now(),
                    'status' => 'sent',
                    'telegram_message_id' => $result['result']['message_id'] ?? null,
                    'content_sent' => $post->content
                ]);
                
                return ['success' => true];
            } else {
                // Log failure
                $error = $result['description'] ?? 'Unknown Telegram API error';
                
                PostLog::create([
                    'post_id' => $post->id,
                    'group_id' => $group->id,
                    'scheduled_time' => $scheduledTime,
                    'sent_at' => now(),
                    'status' => 'failed',
                    'error_message' => $error
                ]);
                
                return ['success' => false, 'error' => $error];
            }
            
        } catch (\Exception $e) {
            // Log exception
            PostLog::create([
                'post_id' => $post->id,
                'group_id' => $group->id,
                'scheduled_time' => $scheduledTime,
                'sent_at' => now(),
                'status' => 'failed',
                'error_message' => $e->getMessage()
            ]);
            
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}