#!/bin/bash
# force-send-messages.sh - Force all scheduled messages to be sent immediately

echo "🚀 Force Send All Scheduled Messages"
echo "==================================="

case "$1" in
    "queue-all")
        echo "📤 Method 1: Queue All Pending Messages for Immediate Processing"
        echo "================================================================"
        
        docker-compose -f docker-compose.dev.yml exec backend php artisan tinker --execute="
            try {
                // Get all pending scheduled posts
                \$posts = App\Models\ScheduledPost::where('status', 'pending')->get();
                \$totalQueued = 0;
                
                echo 'Found ' . \$posts->count() . ' pending posts' . PHP_EOL;
                
                foreach (\$posts as \$post) {
                    foreach (\$post->group_ids as \$groupId) {
                        foreach (\$post->schedule_times_utc as \$time) {
                            // Create job for immediate processing
                            \App\Jobs\SendScheduledMessageJob::dispatch(
                                \$post->id, 
                                \$groupId, 
                                \$time
                            )->onQueue('default');
                            \$totalQueued++;
                        }
                    }
                    
                    // Update post status
                    \$post->update(['status' => 'processing']);
                    echo 'Queued post: ' . \$post->id . ' with ' . count(\$post->group_ids) . ' groups and ' . count(\$post->schedule_times_utc) . ' times' . PHP_EOL;
                }
                
                echo PHP_EOL . '✅ Total jobs queued: ' . \$totalQueued . PHP_EOL;
                echo '⏳ Check queue processing with: docker-compose logs -f queue-worker' . PHP_EOL;
                
            } catch (Exception \$e) {
                echo '❌ Error: ' . \$e->getMessage() . PHP_EOL;
            }
        "
        ;;
        
    "send-now")
        echo "⚡ Method 2: Send All Messages Immediately (Bypass Queue)"
        echo "======================================================="
        
        docker-compose -f docker-compose.dev.yml exec backend php artisan tinker --execute="
            try {
                \$telegramService = app(\App\Services\TelegramService::class);
                \$posts = App\Models\ScheduledPost::where('status', 'pending')->get();
                \$totalSent = 0;
                \$totalFailed = 0;
                
                echo 'Found ' . \$posts->count() . ' pending posts' . PHP_EOL;
                echo 'Starting immediate send...' . PHP_EOL . PHP_EOL;
                
                foreach (\$posts as \$post) {
                    echo 'Processing post: ' . \$post->id . PHP_EOL;
                    
                    foreach (\$post->group_ids as \$groupId) {
                        \$group = \App\Models\Group::find(\$groupId);
                        if (!\$group) {
                            echo '  ❌ Group not found: ' . \$groupId . PHP_EOL;
                            continue;
                        }
                        
                        echo '  📤 Sending to group: ' . \$group->title . ' (' . \$group->telegram_id . ')' . PHP_EOL;
                        
                        try {
                            // Send message
                            \$result = \$telegramService->sendMessage(
                                \$group->telegram_id,
                                \$post->content['text'] ?? 'Test message',
                                \$post->content['media'] ?? []
                            );
                            
                            if (\$result && \$result['ok']) {
                                echo '    ✅ Sent successfully' . PHP_EOL;
                                \$totalSent++;
                                
                                // Log the send
                                \App\Models\PostLog::create([
                                    'post_id' => \$post->id,
                                    'group_id' => \$groupId,
                                    'scheduled_time' => now(),
                                    'scheduled_time_utc' => now(),
                                    'sent_at' => now(),
                                    'status' => 'sent',
                                    'telegram_message_id' => \$result['result']['message_id'] ?? null,
                                    'content_sent' => \$post->content
                                ]);
                            } else {
                                echo '    ❌ Failed: ' . (\$result['description'] ?? 'Unknown error') . PHP_EOL;
                                \$totalFailed++;
                            }
                        } catch (Exception \$e) {
                            echo '    ❌ Exception: ' . \$e->getMessage() . PHP_EOL;
                            \$totalFailed++;
                        }
                        
                        // Small delay to avoid rate limiting
                        usleep(500000); // 0.5 seconds
                    }
                    
                    // Update post status
                    \$post->update([
                        'status' => 'completed',
                        'send_count' => \$totalSent
                    ]);
                    
                    echo '  ✅ Post completed' . PHP_EOL . PHP_EOL;
                }
                
                echo '🎉 Summary:' . PHP_EOL;
                echo '  📤 Messages sent: ' . \$totalSent . PHP_EOL;
                echo '  ❌ Messages failed: ' . \$totalFailed . PHP_EOL;
                
            } catch (Exception \$e) {
                echo '❌ Error: ' . \$e->getMessage() . PHP_EOL;
            }
        "
        ;;
        
    "artisan-command")
        echo "🔧 Method 3: Create Custom Artisan Command"
        echo "=========================================="
        
        echo "Creating artisan command to force send messages..."
        
        docker-compose -f docker-compose.dev.yml exec backend php artisan make:command ForceSendMessages --command=messages:force-send
        
        echo "✅ Command created at: app/Console/Commands/ForceSendMessages.php"
        echo ""
        echo "📝 Add this content to the command file:"
        echo ""
        cat << 'EOF'
<?php
// app/Console/Commands/ForceSendMessages.php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\ScheduledPost;
use App\Models\Group;
use App\Models\PostLog;
use App\Services\TelegramService;

class ForceSendMessages extends Command
{
    protected $signature = 'messages:force-send {--dry-run : Show what would be sent without actually sending}';
    protected $description = 'Force send all pending scheduled messages immediately';

    public function handle(TelegramService $telegramService)
    {
        $dryRun = $this->option('dry-run');
        
        $posts = ScheduledPost::where('status', 'pending')->get();
        
        if ($posts->isEmpty()) {
            $this->info('No pending messages to send.');
            return;
        }
        
        $this->info("Found {$posts->count()} pending posts");
        
        if ($dryRun) {
            $this->warn('DRY RUN MODE - No messages will actually be sent');
        }
        
        $totalSent = 0;
        $totalFailed = 0;
        
        foreach ($posts as $post) {
            $this->info("Processing post: {$post->id}");
            
            foreach ($post->group_ids as $groupId) {
                $group = Group::find($groupId);
                if (!$group) {
                    $this->error("  Group not found: {$groupId}");
                    continue;
                }
                
                $this->line("  Sending to: {$group->title}");
                
                if (!$dryRun) {
                    try {
                        $result = $telegramService->sendMessage(
                            $group->telegram_id,
                            $post->content['text'] ?? 'Test message',
                            $post->content['media'] ?? []
                        );
                        
                        if ($result && $result['ok']) {
                            $this->info('    ✅ Sent successfully');
                            $totalSent++;
                            
                            PostLog::create([
                                'post_id' => $post->id,
                                'group_id' => $groupId,
                                'scheduled_time' => now(),
                                'scheduled_time_utc' => now(),
                                'sent_at' => now(),
                                'status' => 'sent',
                                'telegram_message_id' => $result['result']['message_id'] ?? null,
                                'content_sent' => $post->content
                            ]);
                        } else {
                            $this->error('    ❌ Failed: ' . ($result['description'] ?? 'Unknown error'));
                            $totalFailed++;
                        }
                    } catch (\Exception $e) {
                        $this->error("    ❌ Exception: {$e->getMessage()}");
                        $totalFailed++;
                    }
                    
                    usleep(500000); // 0.5 second delay
                } else {
                    $this->line('    Would send message here...');
                    $totalSent++;
                }
            }
            
            if (!$dryRun) {
                $post->update(['status' => 'completed']);
            }
        }
        
        $this->info("Summary:");
        $this->info("  Messages sent: {$totalSent}");
        if (!$dryRun && $totalFailed > 0) {
            $this->error("  Messages failed: {$totalFailed}");
        }
        
        return 0;
    }
}
EOF
        
        echo ""
        echo "📋 Usage after creating the command:"
        echo "  docker-compose exec backend php artisan messages:force-send --dry-run"
        echo "  docker-compose exec backend php artisan messages:force-send"
        ;;
        
    "process-queue")
        echo "⚡ Method 4: Process Queue Immediately"
        echo "===================================="
        
        echo "🔄 Processing all queued jobs immediately..."
        
        # First, queue all pending messages
        docker-compose -f docker-compose.dev.yml exec backend php artisan tinker --execute="
            \$posts = App\Models\ScheduledPost::where('status', 'pending')->get();
            \$queued = 0;
            foreach (\$posts as \$post) {
                foreach (\$post->group_ids as \$groupId) {
                    foreach (\$post->schedule_times_utc as \$time) {
                        \App\Jobs\SendScheduledMessageJob::dispatch(\$post->id, \$groupId, \$time);
                        \$queued++;
                    }
                }
            }
            echo 'Queued ' . \$queued . ' jobs' . PHP_EOL;
        "
        
        echo ""
        echo "⚡ Processing queue with high concurrency..."
        
        # Process queue immediately with multiple workers
        docker-compose -f docker-compose.dev.yml exec backend php artisan queue:work redis --stop-when-empty --timeout=60 --tries=1 --max-jobs=100 &
        docker-compose -f docker-compose.dev.yml exec backend php artisan queue:work redis --stop-when-empty --timeout=60 --tries=1 --max-jobs=100 &
        docker-compose -f docker-compose.dev.yml exec backend php artisan queue:work redis --stop-when-empty --timeout=60 --tries=1 --max-jobs=100 &
        
        wait
        
        echo "✅ Queue processing completed"
        ;;
        
    "status")
        echo "📊 Check Current Status"
        echo "======================"
        
        docker-compose -f docker-compose.dev.yml exec backend php artisan tinker --execute="
            echo 'Scheduled Posts Status:' . PHP_EOL;
            echo '======================' . PHP_EOL;
            
            \$statuses = \App\Models\ScheduledPost::selectRaw('status, count(*) as count')
                ->groupBy('status')
                ->get();
                
            foreach (\$statuses as \$status) {
                echo \$status->status . ': ' . \$status->count . PHP_EOL;
            }
            
            echo PHP_EOL . 'Recent Post Logs:' . PHP_EOL;
            echo '=================' . PHP_EOL;
            
            \$logs = \App\Models\PostLog::orderBy('created_at', 'desc')->limit(10)->get();
            foreach (\$logs as \$log) {
                echo \$log->status . ' - ' . \$log->created_at . ' - Post: ' . \$log->post_id . PHP_EOL;
            }
            
            echo PHP_EOL . 'Queue Status:' . PHP_EOL;
            echo '=============' . PHP_EOL;
            echo 'Jobs in default queue: ' . \Illuminate\Support\Facades\Redis::llen('queues:default') . PHP_EOL;
        "
        ;;
        
    *)
        echo "🚀 Force Send All Scheduled Messages"
        echo "==================================="
        echo ""
        echo "Usage: $0 [method]"
        echo ""
        echo "Methods:"
        echo "  queue-all           - Queue all pending messages for immediate processing"
        echo "  send-now           - Send all messages immediately (bypass queue)"
        echo "  process-queue      - Process current queue with high concurrency"
        echo "  artisan-command    - Create custom artisan command"
        echo "  status             - Check current message status"
        echo ""
        echo "🚨 Warning: These methods will send ALL pending messages immediately!"
        echo ""
        echo "📋 Recommended approach:"
        echo "  1. $0 status              # Check what messages are pending"
        echo "  2. $0 queue-all           # Queue messages for processing"
        echo "  3. Monitor: docker-compose logs -f queue-worker"
        echo ""
        echo "⚡ For immediate sending (no queue):"
        echo "  $0 send-now               # Sends directly via Telegram API"
        echo ""
        echo "🔧 For custom control:"
        echo "  $0 artisan-command        # Create artisan command"
        echo "  docker-compose exec backend php artisan messages:force-send --dry-run"
        echo ""
        echo "📊 Methods comparison:"
        echo "  • queue-all: Safe, uses existing queue system"
        echo "  • send-now: Immediate, bypasses queue, more risky"
        echo "  • process-queue: Processes existing queue faster"
        echo "  • artisan-command: Most control, reusable"
        ;;
esac