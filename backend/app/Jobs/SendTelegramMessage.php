<?php
// app/Jobs/SendTelegramMessage.php - Optimized for high volume with rate limiting

namespace App\Jobs;

use App\Models\Group;
use App\Models\PostLog;
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

class SendTelegramMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 60;
    public $maxExceptions = 2;
    public $backoff = [30, 120, 300]; // Progressive backoff: 30s, 2min, 5min

    protected $postId;
    protected $groupId;
    protected $scheduledTime;
    protected $content;
    protected $scheduledUtc;

    public function __construct($postId, $groupId, $scheduledTime, $content, $scheduledUtc)
    {
        $this->postId = $postId;
        $this->groupId = $groupId;
        $this->scheduledTime = $scheduledTime;
        $this->content = $content;
        $this->scheduledUtc = $scheduledUtc;
    }

    public function handle(TelegramService $telegramService)
    {
        $startTime = microtime(true);
        
        // Rate limiting: Respect Telegram's 30 messages/second limit
        $this->waitForRateLimit();
        
        // Get group info
        $group = Group::find($this->groupId);
        if (!$group) {
            $this->logFailure('Group not found', $startTime);
            return;
        }

        // Check for duplicates (race condition protection)
        if ($this->isAlreadySent()) {
            Log::info('Message already sent, skipping', [
                'post_id' => $this->postId,
                'group_id' => $this->groupId,
                'scheduled_time' => $this->scheduledTime
            ]);
            return;
        }

        try {
            $text = $this->content['text'] ?? 'Scheduled message';
            $media = $this->content['media'] ?? [];

            Log::info('Sending Telegram message', [
                'post_id' => $this->postId,
                'group_id' => $this->groupId,
                'group_name' => $group->title,
                'scheduled_time' => $this->scheduledTime,
                'attempt' => $this->attempts()
            ]);

            // Send message via Telegram API
            $result = $telegramService->sendMessage(
                $group->telegram_id,
                $text,
                $media
            );

            if ($result && $result['ok']) {
                $this->logSuccess($result, $startTime);
                
                Log::info('Telegram message sent successfully', [
                    'post_id' => $this->postId,
                    'group_id' => $this->groupId,
                    'telegram_message_id' => $result['result']['message_id'] ?? null,
                    'processing_time' => round((microtime(true) - $startTime) * 1000, 2) . 'ms'
                ]);
            } else {
                $error = $result['description'] ?? 'Unknown Telegram API error';
                $this->handleTelegramError($error, $result, $startTime);
            }

        } catch (\Exception $e) {
            $this->handleException($e, $startTime);
        }
    }

    private function waitForRateLimit()
    {
        // Simple rate limiting using Redis
        $rateLimitKey = 'telegram_rate_limit';
        $redis = Redis::connection();
        
        try {
            // Check current minute's message count
            $currentMinute = now()->format('Y-m-d H:i');
            $key = "{$rateLimitKey}:{$currentMinute}";
            
            $currentCount = $redis->incr($key);
            $redis->expire($key, 60); // Expire after 1 minute
            
            // Telegram allows ~30 messages/second, so ~1800/minute
            // We'll be conservative and limit to 1200/minute
            if ($currentCount > 1200) {
                $waitTime = rand(1, 5); // Random wait 1-5 seconds
                Log::info("Rate limit reached, waiting {$waitTime}s", [
                    'current_count' => $currentCount,
                    'minute' => $currentMinute
                ]);
                sleep($waitTime);
            }
            
        } catch (\Exception $e) {
            // If Redis fails, add small random delay as fallback
            usleep(rand(100000, 500000)); // 0.1-0.5 seconds
        }
    }

    private function isAlreadySent()
    {
        return PostLog::where('post_id', $this->postId)
            ->where('group_id', $this->groupId)
            ->where('scheduled_time', $this->scheduledTime)
            ->where('status', 'sent')
            ->exists();
    }

    private function logSuccess($telegramResult, $startTime)
    {
        PostLog::create([
            'post_id' => $this->postId,
            'group_id' => $this->groupId,
            'scheduled_time' => $this->scheduledTime,
            'scheduled_time_utc' => $this->scheduledUtc,
            'sent_at' => now(),
            'status' => 'sent',
            'telegram_message_id' => $telegramResult['result']['message_id'] ?? null,
            'content_sent' => $this->content,
            'processing_duration' => round((microtime(true) - $startTime) * 1000, 2),
            'retry_count' => $this->attempts() - 1,
            'telegram_response' => [
                'ok' => $telegramResult['ok'],
                'result' => $telegramResult['result'] ?? null
            ]
        ]);
    }

    private function logFailure($errorMessage, $startTime, $telegramResponse = null)
    {
        PostLog::create([
            'post_id' => $this->postId,
            'group_id' => $this->groupId,
            'scheduled_time' => $this->scheduledTime,
            'scheduled_time_utc' => $this->scheduledUtc,
            'sent_at' => now(),
            'status' => 'failed',
            'error_message' => $errorMessage,
            'processing_duration' => round((microtime(true) - $startTime) * 1000, 2),
            'retry_count' => $this->attempts() - 1,
            'telegram_response' => $telegramResponse
        ]);
    }

    private function handleTelegramError($error, $result, $startTime)
    {
        $errorCode = $result['error_code'] ?? 0;
        
        // Handle specific Telegram errors
        $retryableErrors = [
            429, // Too Many Requests
            500, // Internal Server Error
            502, // Bad Gateway
            503, // Service Unavailable
            504, // Gateway Timeout
        ];

        if (in_array($errorCode, $retryableErrors)) {
            $this->logFailure("Telegram API error (retryable): {$error}", $startTime, $result);
            
            if ($this->attempts() < $this->tries) {
                $delay = $this->calculateRetryDelay($errorCode);
                Log::warning("Retrying Telegram message in {$delay}s", [
                    'post_id' => $this->postId,
                    'error_code' => $errorCode,
                    'attempt' => $this->attempts()
                ]);
                
                throw new \Exception("Retryable Telegram error: {$error}");
            }
        } else {
            // Non-retryable errors (bot blocked, chat not found, etc.)
            $this->logFailure("Telegram API error (final): {$error}", $startTime, $result);
            
            Log::error('Non-retryable Telegram error', [
                'post_id' => $this->postId,
                'group_id' => $this->groupId,
                'error_code' => $errorCode,
                'error' => $error
            ]);
        }
    }

    private function handleException(\Exception $e, $startTime)
    {
        $this->logFailure("Exception: {$e->getMessage()}", $startTime);
        
        Log::error('Job exception', [
            'post_id' => $this->postId,
            'group_id' => $this->groupId,
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'attempt' => $this->attempts()
        ]);

        if ($this->attempts() < $this->tries) {
            throw $e; // Re-throw for retry
        }
    }

    private function calculateRetryDelay($errorCode)
    {
        switch ($errorCode) {
            case 429: // Rate limit
                return rand(60, 180); // 1-3 minutes
            case 500:
            case 502:
            case 503:
            case 504:
                return rand(30, 60); // 30s-1min for server errors
            default:
                return 30;
        }
    }

    public function failed(\Throwable $exception)
    {
        Log::error('SendTelegramMessage job failed permanently', [
            'post_id' => $this->postId,
            'group_id' => $this->groupId,
            'scheduled_time' => $this->scheduledTime,
            'attempts' => $this->attempts(),
            'exception' => $exception->getMessage()
        ]);

        // Ensure we have a failure log
        if (!$this->isAlreadySent()) {
            $this->logFailure(
                "Job failed after {$this->tries} attempts: {$exception->getMessage()}",
                microtime(true)
            );
        }
    }

    /**
     * Get the tags that should be assigned to the job.
     */
    public function tags()
    {
        return [
            'telegram',
            'post:' . $this->postId,
            'group:' . $this->groupId,
            'scheduled:' . Carbon::parse($this->scheduledUtc)->format('Y-m-d-H')
        ];
    }
}