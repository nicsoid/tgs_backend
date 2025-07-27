<?php
// app/Console/Commands/ForceSendMessages.php - Force send all pending messages

namespace App\Console\Commands;

use App\Models\ScheduledPost;
use App\Models\Group;
use App\Models\PostLog;
use App\Services\TelegramService;
use Illuminate\Console\Command;
use Carbon\Carbon;

class ForceSendMessages extends Command
{
    protected $signature = 'messages:force-send 
                           {--dry-run : Show what would be sent without actually sending}
                           {--post-id= : Force send specific post ID only}';
    
    protected $description = 'Force send all pending messages immediately (bypass time logic)';

    protected $telegramService;

    public function __construct(TelegramService $telegramService)
    {
        parent::__construct();
        $this->telegramService = $telegramService;
    }

    public function handle()
    {
        $dryRun = $this->option('dry-run');
        $postId = $this->option('post-id');
        
        $this->info('🚀 Force sending pending messages...');
        
        if ($dryRun) {
            $this->warn('DRY RUN MODE - No messages will actually be sent');
        }
        
        // Get posts to process
        if ($postId) {
            $posts = ScheduledPost::where('_id', $postId)->get();
            $this->info("Processing specific post: {$postId}");
        } else {
            $posts = ScheduledPost::where('status', 'pending')->get();
            $this->info("Processing all pending posts");
        }
        
        if ($posts->isEmpty()) {
            $this->warn('No pending posts found.');
            return 0;
        }
        
        $this->info("Found {$posts->count()} posts to process");
        
        $totalSent = 0;
        $totalFailed = 0;
        
        foreach ($posts as $post) {
            $this->info("Processing Post {$post->id}...");
            
            $groupIds = $post->group_ids ?? [];
            if (empty($groupIds)) {
                $this->warn("  Skipping: No groups");
                continue;
            }
            
            foreach ($groupIds as $groupId) {
                $group = Group::find($groupId);
                if (!$group) {
                    $this->error("  Group not found: {$groupId}");
                    continue;
                }
                
                $this->line("  Sending to: {$group->title} ({$group->telegram_id})");
                
                // Check if already sent
                $alreadySent = PostLog::where('post_id', $post->id)
                    ->where('group_id', $groupId)
                    ->where('status', 'sent')
                    ->exists();
                
                if ($alreadySent) {
                    $this->line("    Already sent, skipping");
                    continue;
                }
                
                if (!$dryRun) {
                    try {
                        $result = $this->telegramService->sendMessage(
                            $group->telegram_id,
                            $post->content['text'] ?? 'Test message from scheduler',
                            $post->content['media'] ?? []
                        );
                        
                        if ($result && $result['ok']) {
                            $this->info('    ✅ Sent successfully');
                            $totalSent++;
                            
                            // Create log entry
                            PostLog::create([
                                'post_id' => $post->id,
                                'group_id' => $groupId,
                                'scheduled_time' => now()->format('Y-m-d H:i:s'),
                                'sent_at' => now(),
                                'status' => 'sent',
                                'telegram_message_id' => $result['result']['message_id'] ?? null,
                                'content_sent' => $post->content,
                                'processing_duration' => 0,
                                'retry_count' => 0
                            ]);
                            
                        } else {
                            $this->error('    ❌ Failed: ' . ($result['description'] ?? 'Unknown error'));
                            $totalFailed++;
                            
                            // Create failed log
                            PostLog::create([
                                'post_id' => $post->id,
                                'group_id' => $groupId,
                                'scheduled_time' => now()->format('Y-m-d H:i:s'),
                                'sent_at' => now(),
                                'status' => 'failed',
                                'error_message' => $result['description'] ?? 'Unknown error',
                                'retry_count' => 0
                            ]);
                        }
                    } catch (\Exception $e) {
                        $this->error("    ❌ Exception: {$e->getMessage()}");
                        $totalFailed++;
                        
                        PostLog::create([
                            'post_id' => $post->id,
                            'group_id' => $groupId,
                            'scheduled_time' => now()->format('Y-m-d H:i:s'),
                            'sent_at' => now(),
                            'status' => 'failed',
                            'error_message' => $e->getMessage(),
                            'retry_count' => 0
                        ]);
                    }
                    
                    // Small delay to avoid rate limiting
                    usleep(500000); // 0.5 seconds
                } else {
                    $this->line('    [DRY RUN] Would send message here');
                    $totalSent++;
                }
            }
            
            // Update post status
            if (!$dryRun) {
                $post->update([
                    'status' => 'completed',
                    'updated_at' => now()
                ]);
            }
            
            $this->line('');
        }
        
        $this->info('🎉 Force send completed!');
        $this->info("Messages sent: {$totalSent}");
        if ($totalFailed > 0) {
            $this->error("Messages failed: {$totalFailed}");
        }
        
        return 0;
    }
}
