<?php
// app/Jobs/SendScheduledPost.php

namespace App\Jobs;

use App\Models\ScheduledPost;
use App\Models\PostLog;
use App\Services\TelegramService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendScheduledPost implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $post;
    protected $scheduledTime;

    public function __construct(ScheduledPost $post, $scheduledTime)
    {
        $this->post = $post;
        $this->scheduledTime = $scheduledTime;
    }

    public function handle(TelegramService $telegramService)
    {
        try {
            $result = $telegramService->sendMessage(
                $this->post->group->telegram_id,
                $this->post->content['text'],
                $this->post->content['media'] ?? []
            );

            // Log successful send
            PostLog::create([
                'post_id' => $this->post->id,
                'scheduled_time' => $this->scheduledTime,
                'sent_at' => now(),
                'status' => 'sent',
                'telegram_message_id' => $result['result']['message_id'] ?? null
            ]);

            // Update post status
            $this->updatePostStatus();

        } catch (\Exception $e) {
            // Log failed send
            PostLog::create([
                'post_id' => $this->post->id,
                'scheduled_time' => $this->scheduledTime,
                'sent_at' => now(),
                'status' => 'failed',
                'error_message' => $e->getMessage()
            ]);

            // Retry job
            $this->release(300); // Retry after 5 minutes
        }
    }

    private function updatePostStatus()
    {
        $totalScheduled = count($this->post->schedule_times);
        $sentCount = $this->post->logs()->where('status', 'sent')->count();

        if ($sentCount === $totalScheduled) {
            $this->post->update(['status' => 'completed']);
        } else if ($sentCount > 0) {
            $this->post->update(['status' => 'partially_sent']);
        }
    }

    public function failed(\Throwable $exception)
    {
        PostLog::create([
            'post_id' => $this->post->id,
            'scheduled_time' => $this->scheduledTime,
            'sent_at' => now(),
            'status' => 'failed',
            'error_message' => 'Job failed: ' . $exception->getMessage()
        ]);
    }
}
