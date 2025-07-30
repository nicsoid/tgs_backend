<?php
namespace App\Console\Commands;

use App\Models\ScheduledPost;
use App\Models\Group;
use App\Models\PostLog;
use App\Services\TelegramService;
use Illuminate\Console\Command;
use Carbon\Carbon;

class SendMessagesNoDuplicates extends Command
{
    protected $signature = 'messages:send-no-duplicates 
                           {--dry-run : Show what would be sent}
                           {--debug : Show detailed output}
                           {--window=5 : Minutes window around current time}';
    
    protected $description = 'Send messages with bulletproof duplicate prevention';

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
        
        if ($debug) {
            $this->info('🛡️ Message Sender with Bulletproof Duplicate Prevention');
            $this->info('======================================================');
        }
        
        if ($dryRun && $debug) {
            $this->warn('DRY RUN MODE');
        }
        
        $nowUtc = Carbon::now('UTC');
        if ($debug) {
            $this->info("Current UTC: {$nowUtc->format('Y-m-d H:i:s')}");
        }
        
        $startTime = $nowUtc->copy()->subMinutes($window);
        $endTime = $nowUtc->copy()->addMinutes($window);
        
        if ($debug) {
            $this->info("Window: {$startTime->format('H:i')} to {$endTime->format('H:i')}");
        }
        
        $posts = ScheduledPost::all();
        
        if ($posts->isEmpty()) {
            if ($debug) $this->warn('No posts found');
            return 0;
        }
        
        if ($debug) {
            $this->info("Checking {$posts->count()} posts");
        }
        
        $totalSent = 0;
        $totalSkipped = 0;
        
        foreach ($posts as $post) {
            $result = $this->processPostSafely($post, $startTime, $endTime, $nowUtc, $dryRun, $debug);
            $totalSent += $result['sent'];
            $totalSkipped += $result['skipped'];
        }
        
        if ($debug || $totalSent > 0) {
            $this->info('');
            $this->info('📊 RESULTS:');
            $this->info("Messages sent: {$totalSent}");
            $this->info("Messages skipped: {$totalSkipped}");
        }
        
        return 0;
    }
    
    private function processPostSafely($post, $startTime, $endTime, $nowUtc, $dryRun, $debug)
    {
        $sent = 0;
        $skipped = 0;
        
        $userTimes = $post->schedule_times ?? [];
        $utcTimes = $post->schedule_times_utc ?? [];
        $groupIds = $post->group_ids ?? [];
        
        if (empty($groupIds) || empty($utcTimes)) {
            return ['sent' => 0, 'skipped' => 1];
        }
        
        if ($debug) {
            $this->line("Post {$post->id}:");
        }
        
        foreach ($utcTimes as $index => $utcTimeString) {
            $userTime = $userTimes[$index] ?? $utcTimeString;
            
            try {
                $scheduledUtc = Carbon::parse($utcTimeString, 'UTC');
                $isDue = $scheduledUtc->between($startTime, $endTime);
                
                if ($debug) {
                    $diffMinutes = $nowUtc->diffInMinutes($scheduledUtc, false);
                    $this->line("  Time {$index}: {$userTime} → {$utcTimeString} ({$diffMinutes} min)");
                }
                
                if ($isDue) {
                    foreach ($groupIds as $groupId) {
                        // BULLETPROOF duplicate check - check BEFORE doing anything
                        if ($this->isAlreadySentBulletproof($post->id, $groupId, $userTime, $utcTimeString)) {
                            if ($debug) $this->line("    🛡️ BLOCKED: Already sent to group {$groupId}");
                            $skipped++;
                            continue;
                        }
                        
                        $group = Group::find($groupId);
                        if (!$group) {
                            if ($debug) $this->error("    ❌ Group {$groupId} not found");
                            continue;
                        }
                        
                        if ($debug) {
                            $this->line("    📤 Sending to: {$group->title}");
                        }
                        
                        if (!$dryRun) {
                            // ATOMIC send operation with immediate duplicate protection
                            $success = $this->sendMessageAtomically($post, $group, $userTime, $utcTimeString);
                            if ($success) {
                                $sent++;
                                if ($debug) $this->info("    ✅ Sent successfully");
                            } else {
                                if ($debug) $this->error("    ❌ Send failed or already sent");
                                $skipped++;
                            }
                        } else {
                            $sent++;
                            if ($debug) $this->line("    [DRY RUN] Would send");
                        }
                    }
                }
                
            } catch (\Exception $e) {
                if ($debug) $this->error("  Error: " . $e->getMessage());
            }
        }
        
        return ['sent' => $sent, 'skipped' => $skipped];
    }
    
    private function isAlreadySentBulletproof($postId, $groupId, $userTime, $utcTime)
    {
        // Multiple checks to catch any format variation
        $checks = [
            ['post_id' => $postId, 'group_id' => $groupId, 'scheduled_time' => $userTime, 'status' => 'sent'],
            ['post_id' => $postId, 'group_id' => $groupId, 'scheduled_time' => $utcTime, 'status' => 'sent'],
        ];
        
        // Also check by scheduled_time_utc if it exists
        foreach ($checks as $check) {
            if (PostLog::where($check)->exists()) {
                return true;
            }
        }
        
        // Also check if scheduled_time_utc exists and matches
        if (PostLog::where('post_id', $postId)
                  ->where('group_id', $groupId)
                  ->where('scheduled_time_utc', $utcTime)
                  ->where('status', 'sent')
                  ->exists()) {
            return true;
        }
        
        return false;
    }
    
    private function sendMessageAtomically($post, $group, $userTime, $utcTime)
    {
        // CRITICAL: Create log entry FIRST to prevent race conditions
        try {
            // Create a "sending" log immediately to block duplicates
            $logId = PostLog::create([
                'post_id' => $post->id,
                'group_id' => $group->id,
                'scheduled_time' => $userTime,
                'scheduled_time_utc' => $utcTime,
                'sent_at' => now(),
                'status' => 'sending', // Temporary status
                'content_sent' => $post->content
            ])->id;
            
        } catch (\Exception $e) {
            // If log creation fails (likely duplicate), don't send
            if (str_contains($e->getMessage(), 'E11000') || str_contains($e->getMessage(), 'duplicate')) {
                return false; // Already being processed
            }
            throw $e; // Re-throw other errors
        }
        
        // Now try to send the message
        try {
            $text = $post->content['text'] ?? 'Scheduled message';
            $media = $post->content['media'] ?? [];
            
            $result = $this->telegramService->sendMessage(
                $group->telegram_id,
                $text,
                $media
            );
            
            if ($result && $result['ok']) {
                // Update log to "sent"
                PostLog::where('_id', $logId)->update([
                    'status' => 'sent',
                    'telegram_message_id' => $result['result']['message_id'] ?? null,
                    'sent_at' => now()
                ]);
                
                return true;
            } else {
                // Update log to "failed"
                PostLog::where('_id', $logId)->update([
                    'status' => 'failed',
                    'error_message' => $result['description'] ?? 'Unknown error',
                    'sent_at' => now()
                ]);
                
                return false;
            }
            
        } catch (\Exception $e) {
            // Update log to "failed"
            PostLog::where('_id', $logId)->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'sent_at' => now()
            ]);
            
            return false;
        }
    }
}
