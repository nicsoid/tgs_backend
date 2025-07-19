<?php
// Debug commands to check why messages aren't being sent

// 1. First, let's check if jobs are actually processing
// Run this in terminal:
// php artisan queue:work --verbose --timeout=30

// 2. Check the SendScheduledPost job for better error handling
// app/Jobs/SendScheduledPost.php - ENHANCED VERSION

namespace App\Jobs;

use App\Models\ScheduledPost;
use App\Models\PostLog;
use App\Models\Group;
use App\Services\TelegramService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendScheduledPost implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $post;
    protected $scheduledTime;
    protected $groupId;

    public function __construct(ScheduledPost $post, $scheduledTime, $groupId = null)
    {
        $this->post = $post;
        $this->scheduledTime = $scheduledTime;
        $this->groupId = $groupId;
    }

    public function handle(TelegramService $telegramService)
    {
        Log::info('SendScheduledPost job started', [
            'post_id' => $this->post->id,
            'scheduled_time' => $this->scheduledTime,
            'group_id' => $this->groupId
        ]);

        // Get groups to send to
        $groupIds = $this->groupId ? [$this->groupId] : ($this->post->group_ids ?? []);
        
        if (empty($groupIds)) {
            Log::error('No groups found for post', ['post_id' => $this->post->id]);
            return;
        }

        Log::info('Processing groups', [
            'post_id' => $this->post->id,
            'group_ids' => $groupIds,
            'group_count' => count($groupIds)
        ]);

        // Send to each group
        foreach ($groupIds as $groupId) {
            try {
                Log::info('Processing group', [
                    'post_id' => $this->post->id,
                    'group_id' => $groupId
                ]);

                // Find the group
                $group = Group::where('_id', $groupId)
                    ->orWhere('id', $groupId)
                    ->first();
                
                if (!$group) {
                    Log::error('Group not found in database', [
                        'group_id' => $groupId,
                        'post_id' => $this->post->id
                    ]);
                    
                    // Still create a failed log entry
                    PostLog::create([
                        'post_id' => $this->post->id,
                        'group_id' => $groupId,
                        'scheduled_time' => $this->scheduledTime,
                        'sent_at' => now(),
                        'status' => 'failed',
                        'error_message' => 'Group not found in database'
                    ]);
                    continue;
                }

                Log::info('Group found', [
                    'group_id' => $groupId,
                    'group_title' => $group->title,
                    'telegram_id' => $group->telegram_id
                ]);

                // Check if already sent to this group at this time
                $existingLog = PostLog::where('post_id', $this->post->id)
                    ->where('group_id', $groupId)
                    ->where('scheduled_time', $this->scheduledTime)
                    ->first();

                if ($existingLog) {
                    Log::info('Message already processed for this group', [
                        'post_id' => $this->post->id,
                        'group_id' => $groupId,
                        'scheduled_time' => $this->scheduledTime,
                        'existing_status' => $existingLog->status
                    ]);
                    continue;
                }

                // Get message content
                $content = $this->post->content;
                $text = $content['text'] ?? '';
                $media = $content['media'] ?? [];

                Log::info('Sending message to Telegram', [
                    'post_id' => $this->post->id,
                    'group_id' => $groupId,
                    'group_telegram_id' => $group->telegram_id,
                    'text_length' => strlen($text),
                    'media_count' => count($media)
                ]);

                // Send the message
                $result = $telegramService->sendMessage(
                    $group->telegram_id,
                    $text,
                    $media
                );

                Log::info('Telegram API response', [
                    'post_id' => $this->post->id,
                    'group_id' => $groupId,
                    'result' => $result
                ]);

                // Log successful send
                PostLog::create([
                    'post_id' => $this->post->id,
                    'group_id' => $groupId,
                    'scheduled_time' => $this->scheduledTime,
                    'sent_at' => now(),
                    'status' => 'sent',
                    'telegram_message_id' => $result['result']['message_id'] ?? null
                ]);

                Log::info('Message sent successfully', [
                    'post_id' => $this->post->id,
                    'group_id' => $groupId,
                    'group_title' => $group->title,
                    'scheduled_time' => $this->scheduledTime,
                    'telegram_message_id' => $result['result']['message_id'] ?? null
                ]);

            } catch (\Exception $e) {
                Log::error('Failed to send message', [
                    'post_id' => $this->post->id,
                    'group_id' => $groupId,
                    'scheduled_time' => $this->scheduledTime,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);

                // Log failed send
                PostLog::create([
                    'post_id' => $this->post->id,
                    'group_id' => $groupId,
                    'scheduled_time' => $this->scheduledTime,
                    'sent_at' => now(),
                    'status' => 'failed',
                    'error_message' => $e->getMessage()
                ]);

                // Don't retry automatically - let admin handle retries
                // $this->release(300);
            }
        }

        // Update post status after all groups processed
        $this->updatePostStatus();
        
        Log::info('SendScheduledPost job completed', [
            'post_id' => $this->post->id
        ]);
    }

    private function updatePostStatus()
    {
        $totalScheduled = $this->post->total_scheduled ?? (count($this->post->group_ids ?? []) * count($this->post->schedule_times ?? []));
        $sentCount = $this->post->logs()->where('status', 'sent')->count();
        $failedCount = $this->post->logs()->where('status', 'failed')->count();

        Log::info('Updating post status', [
            'post_id' => $this->post->id,
            'total_scheduled' => $totalScheduled,
            'sent_count' => $sentCount,
            'failed_count' => $failedCount
        ]);

        if ($sentCount === $totalScheduled) {
            $this->post->update(['status' => 'completed']);
            Log::info('Post marked as completed', ['post_id' => $this->post->id]);
        } else if ($sentCount > 0) {
            $this->post->update(['status' => 'partially_sent']);
            Log::info('Post marked as partially_sent', ['post_id' => $this->post->id]);
        } else if ($failedCount > 0 && $sentCount === 0) {
            $this->post->update(['status' => 'failed']);
            Log::info('Post marked as failed', ['post_id' => $this->post->id]);
        }
    }

    public function failed(\Throwable $exception)
    {
        Log::error('SendScheduledPost job completely failed', [
            'post_id' => $this->post->id,
            'scheduled_time' => $this->scheduledTime,
            'group_id' => $this->groupId,
            'exception' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);

        // Log failure for all groups if job completely fails
        $groupIds = $this->groupId ? [$this->groupId] : ($this->post->group_ids ?? []);
        
        foreach ($groupIds as $groupId) {
            PostLog::create([
                'post_id' => $this->post->id,
                'group_id' => $groupId,
                'scheduled_time' => $this->scheduledTime,
                'sent_at' => now(),
                'status' => 'failed',
                'error_message' => 'Job failed: ' . $exception->getMessage()
            ]);
        }
    }
}