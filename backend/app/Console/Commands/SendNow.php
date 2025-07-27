<?php
// app/Console/Commands/SendNow.php - Send messages immediately to groups

namespace App\Console\Commands;

use App\Models\ScheduledPost;
use App\Models\Group;
use App\Models\PostLog;
use App\Services\TelegramService;
use Illuminate\Console\Command;

class SendNow extends Command
{
    protected $signature = 'send:now 
                           {--dry-run : Show what would be sent}
                           {--post-id= : Send specific post only}';
    
    protected $description = 'Send messages to groups immediately (bypass scheduling)';

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
        
        $this->info('🚀 Sending messages to groups immediately...');
        
        if ($dryRun) {
            $this->warn('DRY RUN MODE - No messages will actually be sent');
        }
        
        // Get posts
        if ($postId) {
            $posts = ScheduledPost::where('_id', $postId)->get();
        } else {
            $posts = ScheduledPost::where('status', 'pending')->get();
        }
        
        if ($posts->isEmpty()) {
            $this->warn('No pending posts found.');
            return 0;
        }
        
        $this->info("Found {$posts->count()} posts to send");
        
        $totalSent = 0;
        $totalFailed = 0;
        
        foreach ($posts as $post) {
            $this->info("📤 Processing Post {$post->id}...");
            
            $groupIds = $post->group_ids ?? [];
            if (empty($groupIds)) {
                $this->warn("  No groups found, skipping");
                continue;
            }
            
            foreach ($groupIds as $groupId) {
                $group = Group::find($groupId);
                if (!$group) {
                    $this->error("  Group not found: {$groupId}");
                    continue;
                }
                
                $this->line("  → Sending to: {$group->title} (ID: {$group->telegram_id})");
                
                // Check if already sent
                $alreadySent = PostLog::where('post_id', $post->id)
                    ->where('group_id', $groupId)
                    ->where('status', 'sent')
                    ->exists();
                
                if ($alreadySent) {
                    $this->line("    ⏭️  Already sent, skipping");
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
                            $this->info('    ✅ Sent successfully!');
                            $totalSent++;
                            
                            // Create success log
                            PostLog::create([
                                'post_id' => $post->id,
                                'group_id' => $groupId,
                                'scheduled_time' => now()->format('Y-m-d H:i:s'),
                                'sent_at' => now(),
                                'status' => 'sent',
                                'telegram_message_id' => $result['result']['message_id'] ?? null,
                                'content_sent' => $post->content
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
                                'error_message' => $result['description'] ?? 'Unknown error'
                            ]);
                        }
                    } catch (\Exception $e) {
                        $this->error("    ❌ Exception: {$e->getMessage()}");
                        $totalFailed++;
                    }
                    
                    // Small delay to avoid rate limiting
                    usleep(500000); // 0.5 seconds
                } else {
                    $this->line('    [DRY RUN] Would send message here');
                    $totalSent++;
                }
            }
            
            // Update post status
            if (!$dryRun && $totalSent > 0) {
                $post->update(['status' => 'completed']);
            }
        }
        
        $this->info('');
        $this->info('🎉 Sending completed!');
        $this->info("Messages sent: {$totalSent}");
        if ($totalFailed > 0) {
            $this->error("Messages failed: {$totalFailed}");
        }
        
        return 0;
    }
}
