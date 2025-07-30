<?php
namespace App\Console\Commands;

use App\Models\ScheduledPost;
use App\Models\Group;
use App\Models\PostLog;
use App\Services\TelegramService;
use Illuminate\Console\Command;
use Carbon\Carbon;

class SendDueMessagesFixed extends Command
{
    protected $signature = 'messages:send-due-fixed 
                           {--dry-run : Show what would be sent}
                           {--debug : Show detailed output}
                           {--window=2 : Minutes window around current time}';
    
    protected $description = 'Send messages with improved duplicate prevention';

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
            $this->info('🕐 Fixed Message Sender (Better Duplicate Prevention)');
            $this->info('=====================================================');
        }
        
        if ($dryRun && $debug) {
            $this->warn('DRY RUN MODE - No messages will be sent');
        }
        
        $nowUtc = Carbon::now('UTC');
        if ($debug) {
            $this->info("Current UTC: {$nowUtc->format('Y-m-d H:i:s')}");
        }
        
        $startTime = $nowUtc->copy()->subMinutes($window);
        $endTime = $nowUtc->copy()->addMinutes($window);
        
        if ($debug) {
            $this->info("Processing window: {$startTime->format('H:i')} to {$endTime->format('H:i')}");
        }
        
        $posts = ScheduledPost::all();
        
        if ($posts->isEmpty()) {
            if ($debug) $this->warn('No posts found');
            return 0;
        }
        
        if ($debug) {
            $this->info("Found {$posts->count()} posts to check");
        }
        
        $totalSent = 0;
        $totalSkipped = 0;
        
        foreach ($posts as $post) {
            $result = $this->processPost($post, $startTime, $endTime, $nowUtc, $dryRun, $debug);
            $totalSent += $result['sent'];
            $totalSkipped += $result['skipped'];
        }
        
        if ($debug || $totalSent > 0) {
            $this->info('');
            $this->info('📊 RESULTS:');
            $this->info("Messages sent: {$totalSent}");
            $this->info("Messages skipped: {$totalSkipped}");
            
            if ($totalSent > 0 && !$dryRun) {
                $this->info('✅ Check your Telegram groups for new messages!');
            }
        }
        
        return 0;
    }
    
    private function processPost($post, $startTime, $endTime, $nowUtc, $dryRun, $debug)
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
            $this->line("Checking Post {$post->id}...");
        }
        
        foreach ($utcTimes as $index => $utcTimeString) {
            $userTime = $userTimes[$index] ?? $utcTimeString;
            
            try {
                $scheduledUtc = Carbon::parse($utcTimeString, 'UTC');
                
                $isDue = $scheduledUtc->between($startTime, $endTime);
                
                if ($debug) {
                    $diffMinutes = $nowUtc->diffInMinutes($scheduledUtc, false);
                    $this->line("  Time {$index}: {$userTime} → {$utcTimeString}");
                    $this->line("    Difference: {$diffMinutes} minutes");
                    $this->line("    Due now: " . ($isDue ? 'YES ✅' : 'NO ❌'));
                }
                
                if ($isDue) {
                    foreach ($groupIds as $groupId) {
                        // IMPROVED: Use multiple keys for duplicate checking
                        if ($this->isAlreadySent($post->id, $groupId, $userTime, $utcTimeString)) {
                            if ($debug) $this->line("    Already sent to group {$groupId}");
                            $skipped++;
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
                            $result = $this->sendMessage($post, $group, $userTime, $utcTimeString);
                            if ($result['success']) {
                                $sent++;
                                if ($debug) $this->info("    ✅ Sent to {$group->title}");
                            } else {
                                if ($debug) $this->error("    ❌ Failed: {$result['error']}");
                            }
                        } else {
                            $sent++;
                            if ($debug) $this->line("    [DRY RUN] Would send to {$group->title}");
                        }
                    }
                }
                
            } catch (\Exception $e) {
                if ($debug) $this->error("  Error processing time {$utcTimeString}: " . $e->getMessage());
            }
        }
        
        return ['sent' => $sent, 'skipped' => $skipped];
    }
    
    private function isAlreadySent($postId, $groupId, $userTime, $utcTime)
    {
        // Check multiple time formats to prevent duplicates
        return PostLog::where('post_id', $postId)
            ->where('group_id', $groupId)
            ->where('status', 'sent')
            ->where(function($query) use ($userTime, $utcTime) {
                $query->where('scheduled_time', $userTime)
                      ->orWhere('scheduled_time', $utcTime)
                      ->orWhere('scheduled_time_utc', $utcTime);
            })
            ->exists();
    }
    
    private function sendMessage($post, $group, $userTime, $utcTime)
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
                // Create success log with consistent format
                PostLog::create([
                    'post_id' => $post->id,
                    'group_id' => $group->id,
                    'scheduled_time' => $userTime,
                    'scheduled_time_utc' => $utcTime,
                    'sent_at' => now(),
                    'status' => 'sent',
                    'telegram_message_id' => $result['result']['message_id'] ?? null,
                    'content_sent' => $post->content
                ]);
                
                return ['success' => true];
            } else {
                $error = $result['description'] ?? 'Unknown error';
                
                PostLog::create([
                    'post_id' => $post->id,
                    'group_id' => $group->id,
                    'scheduled_time' => $userTime,
                    'scheduled_time_utc' => $utcTime,
                    'sent_at' => now(),
                    'status' => 'failed',
                    'error_message' => $error
                ]);
                
                return ['success' => false, 'error' => $error];
            }
            
        } catch (\Exception $e) {
            PostLog::create([
                'post_id' => $post->id,
                'group_id' => $group->id,
                'scheduled_time' => $userTime,
                'scheduled_time_utc' => $utcTime,
                'sent_at' => now(),
                'status' => 'failed',
                'error_message' => $e->getMessage()
            ]);
            
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
