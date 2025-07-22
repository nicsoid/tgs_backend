<?php
// app/Jobs/SendScheduledPost.php - Enhanced for Scale with Strategy 1
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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Carbon\Carbon;

class SendScheduledPost implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $postId;
    protected $scheduledTime;
    protected $groupId;

    public $tries = 3;
    public $timeout = 120;
    public $maxExceptions = 1;
    public $backoff = [30, 60, 120]; // Exponential backoff

    public function __construct($postId, $scheduledTime, $groupId)
    {
        $this->postId = $postId;
        $this->scheduledTime = $scheduledTime;
        $this->groupId = $groupId;
        
        // Set queue based on load balancing
        $this->onQueue($this->getOptimalQueue());
    }

    public function handle(TelegramService $telegramService)
    {
        $startTime = microtime(true);
        
        // Create unique lock key to prevent duplicate processing
        $lockKey = "send_post:{$this->postId}:{$this->groupId}:" . md5($this->scheduledTime);
        
        // Try to acquire lock with 5-minute timeout
        $lock = Cache::lock($lockKey, 300);
        
        if (!$lock->get()) {
            Log::info('Job already processing, skipping duplicate', [
                'post_id' => $this->postId,
                'group_id' => $this->groupId,
                'scheduled_time' => $this->scheduledTime,
                'lock_key' => $lockKey
            ]);
            return;
        }

        try {
            // Double-check: verify not already sent (database-level protection)
            if ($this->isAlreadyProcessed()) {
                Log::info('Message already processed, skipping', [
                    'post_id' => $this->postId,
                    'group_id' => $this->groupId,
                    'scheduled_time' => $this->scheduledTime
                ]);
                return;
            }

            // Get fresh post data (Strategy 1: Always use current content)
            $post = ScheduledPost::find($this->postId);
            if (!$post) {
                Log::error('Post not found during job execution', [
                    'post_id' => $this->postId
                ]);
                $this->createFailedLog('Post not found', $startTime);
                return;
            }

            // Validate post is still schedulable
            if (!in_array($post->status, ['pending', 'partially_sent'])) {
                Log::info('Post status changed, skipping send', [
                    'post_id' => $this->postId,
                    'status' => $post->status
                ]);
                return;
            }

            // Get target group
            $group = Group::find($this->groupId);
            if (!$group) {
                Log::error('Group not found during job execution', [
                    'group_id' => $this->groupId,
                    'post_id' => $this->postId
                ]);
                $this->createFailedLog('Group not found', $startTime);
                return;
            }

            // Extract current content (fresh from database - allows edits)
            $currentContent = $post->content;
            $text = $currentContent['text'] ?? '';
            $media = $currentContent['media'] ?? [];

            if (empty($text) && empty($media)) {
                Log::error('Post has no content to send', [
                    'post_id' => $this->postId
                ]);
                $this->createFailedLog('No content to send', $startTime);
                return;
            }

            Log::info('Sending message with current content', [
                'post_id' => $this->postId,
                'group_id' => $this->groupId,
                'group_title' => $group->title,
                'text_length' => strlen($text),
                'media_count' => count($media),
                'scheduled_time' => $this->scheduledTime
            ]);

            // Send message via Telegram API
            $result = $telegramService->sendMessage(
                $group->telegram_id,
                $text,
                $media
            );

            // Record successful send
            $this->createSuccessLog($result, $currentContent, $startTime);

            // Update post statistics
            $this->updatePostStatistics($post);

            Log::info('Message sent successfully', [
                'post_id' => $this->postId,
                'group_id' => $this->groupId,
                'group_title' => $group->title,
                'telegram_message_id' => $result['result']['message_id'] ?? null,
                'processing_time' => round((microtime(true) - $startTime) * 1000, 2) . 'ms'
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to send scheduled message', [
                'post_id' => $this->postId,
                'group_id' => $this->groupId,
                'scheduled_time' => $this->scheduledTime,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'attempt' => $this->attempts()
            ]);

            $this->createFailedLog($e->getMessage(), $startTime, $e);

            // Re-throw for retry mechanism if not final attempt
            if ($this->attempts() < $this->tries) {
                throw $e;
            }

        } finally {
            $lock->release();
        }
    }

    private function isAlreadyProcessed(): bool
    {
        return PostLog::where('post_id', $this->postId)
            ->where('group_id', $this->groupId)
            ->where('scheduled_time', $this->scheduledTime)
            ->exists();
    }

    private function createSuccessLog($telegramResult, $contentSent, $startTime)
    {
        PostLog::create([
            'post_id' => $this->postId,
            'group_id' => $this->groupId,
            'scheduled_time' => $this->scheduledTime,
            'sent_at' => now(),
            'status' => 'sent',
            'telegram_message_id' => $telegramResult['result']['message_id'] ?? null,
            'content_sent' => [
                'text' => $contentSent['text'] ?? '',
                'media_count' => count($contentSent['media'] ?? []),
                'sent_version' => now()->toISOString() // Track when this version was sent
            ],
            'processing_duration' => round((microtime(true) - $startTime) * 1000, 2),
            'retry_count' => $this->attempts() - 1
        ]);
    }

    private function createFailedLog($errorMessage, $startTime, $exception = null)
    {
        PostLog::create([
            'post_id' => $this->postId,
            'group_id' => $this->groupId,
            'scheduled_time' => $this->scheduledTime,
            'sent_at' => now(),
            'status' => 'failed',
            'error_message' => $errorMessage,
            'error_details' => $exception ? [
                'exception_class' => get_class($exception),
                'file' => $exception->getFile(),
                'line' => $exception->getLine()
            ] : null,
            'processing_duration' => round((microtime(true) - $startTime) * 1000, 2),
            'retry_count' => $this->attempts() - 1
        ]);
    }

    private function updatePostStatistics(ScheduledPost $post)
    {
        // Update post status based on completion
        $totalScheduled = $post->total_scheduled ?? 0;
        $sentCount = PostLog::where('post_id', $post->id)->where('status', 'sent')->count();
        $failedCount = PostLog::where('post_id', $post->id)->where('status', 'failed')->count();

        if ($sentCount === $totalScheduled) {
            $post->update(['status' => 'completed']);
        } elseif ($sentCount > 0) {
            $post->update(['status' => 'partially_sent']);
        } elseif ($failedCount > 0 && $sentCount === 0) {
            $post->update(['status' => 'failed']);
        }
    }

    private function getOptimalQueue(): string
    {
        // Distribute load across multiple queues
        $queues = ['telegram-messages-1', 'telegram-messages-2', 'telegram-messages-3'];
        $queueIndex = crc32($this->postId . $this->groupId) % count($queues);
        return $queues[$queueIndex];
    }

    public function failed(\Throwable $exception)
    {
        Log::error('SendScheduledPost job failed permanently', [
            'post_id' => $this->postId,
            'group_id' => $this->groupId,
            'scheduled_time' => $this->scheduledTime,
            'final_attempt' => $this->attempts(),
            'exception' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);

        // Ensure we have a failed log entry
        if (!$this->isAlreadyProcessed()) {
            $this->createFailedLog(
                'Job failed after ' . $this->tries . ' attempts: ' . $exception->getMessage(),
                microtime(true),
                $exception
            );
        }
    }
}