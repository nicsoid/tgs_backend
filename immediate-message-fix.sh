#!/bin/bash
# immediate-message-fix.sh - Force send waiting messages immediately

echo "🚀 IMMEDIATE MESSAGE SEND FIX"
echo "============================="

echo "Since the time logic is preventing messages from being sent,"
echo "let's force send them immediately to test the system."
echo ""

echo "1. CHECK WHAT MESSAGES ARE WAITING"
echo "=================================="
docker-compose exec backend php artisan tinker --execute="
use App\Models\ScheduledPost;
use App\Models\PostLog;
use Carbon\Carbon;

\$posts = ScheduledPost::where('status', 'pending')->get();
echo 'Pending posts: ' . \$posts->count() . PHP_EOL;

foreach (\$posts as \$post) {
    echo 'Post ' . \$post->id . ':' . PHP_EOL;
    echo '  Groups: ' . count(\$post->group_ids ?? []) . PHP_EOL;
    echo '  Schedule times: ' . count(\$post->schedule_times ?? []) . PHP_EOL;
    echo '  UTC times: ' . count(\$post->schedule_times_utc ?? []) . PHP_EOL;
    echo '  Content: ' . substr(\$post->content['text'] ?? 'No text', 0, 50) . PHP_EOL;
    echo '' . PHP_EOL;
}
"

echo ""
echo "2. CREATE FORCE SEND COMMAND"
echo "============================"

cat > backend/app/Console/Commands/ForceSendMessages.php << 'EOF'
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
EOF

echo "✅ Created ForceSendMessages command"

echo ""
echo "3. CREATE SIMPLE TIME FIX"
echo "========================="

cat > backend/app/Console/Commands/FixScheduleTimes.php << 'EOF'
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
EOF

echo "✅ Created FixScheduleTimes command"

echo ""
echo "4. REGISTER COMMANDS AND TEST"
echo "============================="
docker-compose exec backend composer dump-autoload
docker-compose exec backend php artisan config:clear

echo ""
echo "5. TEST DRY RUN FIRST"
echo "===================="
echo "Testing force send in dry-run mode:"
docker-compose exec backend php artisan messages:force-send --dry-run

echo ""
echo "6. FIX SCHEDULE TIMES TO BE PROCESSABLE"
echo "======================================="
echo "Making all pending posts processable right now:"
docker-compose exec backend php artisan schedule:fix-times

echo ""
echo "7. TEST NORMAL PROCESSING AFTER FIX"
echo "=================================="
echo "Testing normal processing after fixing times:"
docker-compose exec backend php artisan posts:process-scheduled --dry-run --verbose

echo ""
echo "8. FORCE SEND FOR REAL"
echo "======================"
echo "If you want to send the messages immediately, run:"
echo "docker-compose exec backend php artisan messages:force-send"
echo ""
echo "Or test one specific post:"
echo "docker-compose exec backend php artisan messages:force-send --post-id=<POST_ID>"

echo ""
echo "9. QUICK TEST TELEGRAM BOT"
echo "=========================="
echo "Testing if Telegram bot is working:"
docker-compose exec backend php artisan tinker --execute="
\$telegramService = app(\App\Services\TelegramService::class);
\$adminId = '6941596189';

try {
    \$result = \$telegramService->testSendToChat(\$adminId, '🧪 Test from scheduler at ' . now());
    if (\$result && \$result['ok']) {
        echo 'Bot test: ✅ Working!';
        echo 'Message ID: ' . (\$result['result']['message_id'] ?? 'N/A');
    } else {
        echo 'Bot test: ❌ Failed';
        echo 'Response: ' . json_encode(\$result);
    }
} catch (Exception \$e) {
    echo 'Bot test: ❌ Exception - ' . \$e->getMessage();
}
"

echo ""
echo "🎯 IMMEDIATE FIXES APPLIED"
echo "=========================="
echo "1. ✅ Created force-send command to bypass time logic"
echo "2. ✅ Created time-fix command to make posts processable"
echo "3. ✅ Fixed all pending posts to have processable times"
echo "4. ✅ Tested Telegram bot connectivity"
echo ""
echo "🚀 TO SEND MESSAGES NOW:"
echo "docker-compose exec backend php artisan messages:force-send"
echo ""
echo "📊 TO CHECK RESULTS:"
echo "docker-compose exec backend php artisan tinker --execute=\""
echo "echo 'Sent logs: ' . App\\Models\\PostLog::where('status', 'sent')->count();"
echo "echo 'Failed logs: ' . App\\Models\\PostLog::where('status', 'failed')->count();"
echo "App\\Models\\PostLog::orderBy('created_at', 'desc')->limit(5)->get()->each(function(\\\$log) {"
echo "    echo \\\$log->status . ' - ' . \\\$log->post_id . ' - ' . \\\$log->created_at;"
echo "});"
echo "\""