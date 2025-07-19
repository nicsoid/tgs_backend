<?php
namespace App\Console\Commands;

use App\Models\ScheduledPost;
use App\Models\Group;
use App\Services\TelegramService;
use Illuminate\Console\Command;

class TestTelegramSending extends Command
{
    protected $signature = 'telegram:test 
                           {--post-id= : Test specific post}
                           {--group-id= : Test specific group}
                           {--bot-only : Test bot connection only}';
    
    protected $description = 'Test Telegram sending functionality';

    protected $telegramService;

    public function __construct(TelegramService $telegramService)
    {
        parent::__construct();
        $this->telegramService = $telegramService;
    }

    public function handle()
    {
        $this->info('🧪 Testing Telegram Sending');
        $this->info('===========================');

        // Test 1: Bot connection
        $this->info('1. Testing bot connection...');
        try {
            $botInfo = $this->telegramService->testBotConnection();
            if ($botInfo && $botInfo['ok']) {
                $this->info("✅ Bot connected: @{$botInfo['result']['username']}");
            } else {
                $this->error('❌ Bot connection failed');
                return 1;
            }
        } catch (\Exception $e) {
            $this->error("❌ Bot connection error: " . $e->getMessage());
            return 1;
        }

        if ($this->option('bot-only')) {
            return 0;
        }

        // Test 2: Group access
        $groupId = $this->option('group-id');
        if ($groupId) {
            $group = Group::find($groupId);
        } else {
            $group = Group::first();
        }

        if (!$group) {
            $this->error('❌ No groups found to test');
            return 1;
        }

        $this->info("2. Testing group access: {$group->title} (ID: {$group->telegram_id})");
        try {
            $result = $this->telegramService->testSendToChat(
                $group->telegram_id,
                "🧪 Test message from Telegram Scheduler at " . now()
            );
            
            if ($result && $result['ok']) {
                $this->info("✅ Test message sent successfully");
                $this->info("   Message ID: {$result['result']['message_id']}");
            } else {
                $this->error("❌ Test message failed");
                $this->error("   Response: " . json_encode($result));
            }
        } catch (\Exception $e) {
            $this->error("❌ Test message error: " . $e->getMessage());
        }

        // Test 3: Process specific post
        $postId = $this->option('post-id');
        if ($postId) {
            $this->info("3. Testing specific post: {$postId}");
            $post = ScheduledPost::find($postId);
            
            if (!$post) {
                $this->error('❌ Post not found');
                return 1;
            }

            $this->info("   Post status: {$post->status}");
            $this->info("   Groups: " . count($post->group_ids ?? []));
            $this->info("   Schedule times: " . count($post->schedule_times ?? []));
            
            // Check logs
            $logs = $post->logs()->get();
            $this->info("   Existing logs: " . $logs->count());
            
            foreach ($logs as $log) {
                $status = $log->status === 'sent' ? '✅' : '❌';
                $this->line("     {$status} {$log->scheduled_time} → {$log->status}");
                if ($log->error_message) {
                    $this->line("       Error: {$log->error_message}");
                }
            }
        }

        return 0;
    }
}