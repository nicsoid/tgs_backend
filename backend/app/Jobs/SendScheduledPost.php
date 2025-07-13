<?php
// app/Jobs/SendScheduledPost.php

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
        // Get groups to send to
        $groupIds = $this->groupId ? [$this->groupId] : ($this->post->group_ids ?? []);
        
        if (empty($groupIds)) {
            \Log::error('No groups found for post', ['post_id' => $this->post->id]);
            return;
        }

        // Send to each group
        foreach ($groupIds as $groupId) {
            try {
                // Find the group
                $group = Group::where('_id', $groupId)
                    ->orWhere('id', $groupId)
                    ->first();
                
                if (!$group) {
                    \Log::error('Group not found', ['group_id' => $groupId]);
                    continue;
                }

                // Check if already sent to this group at this time
                $existingLog = PostLog::where('post_id', $this->post->id)
                    ->where('group_id', $groupId)
                    ->where('scheduled_time', $this->scheduledTime)
                    ->first();

                if ($existingLog) {
                    \Log::info('Message already sent to this group', [
                        'post_id' => $this->post->id,
                        'group_id' => $groupId,
                        'scheduled_time' => $this->scheduledTime
                    ]);
                    continue;
                }

                // Send the message
                $result = $telegramService->sendMessage(
                    $group->telegram_id,
                    $this->post->content['text'],
                    $this->post->content['media'] ?? []
                );

                // Log successful send
                PostLog::create([
                    'post_id' => $this->post->id,
                    'group_id' => $groupId,
                    'scheduled_time' => $this->scheduledTime,
                    'sent_at' => now(),
                    'status' => 'sent',
                    'telegram_message_id' => $result['result']['message_id'] ?? null
                ]);

                \Log::info('Message sent successfully', [
                    'post_id' => $this->post->id,
                    'group_id' => $groupId,
                    'group_title' => $group->title,
                    'scheduled_time' => $this->scheduledTime
                ]);

            } catch (\Exception $e) {
                // Log failed send
                PostLog::create([
                    'post_id' => $this->post->id,
                    'group_id' => $groupId,
                    'scheduled_time' => $this->scheduledTime,
                    'sent_at' => now(),
                    'status' => 'failed',
                    'error_message' => $e->getMessage()
                ]);

                \Log::error('Failed to send message', [
                    'post_id' => $this->post->id,
                    'group_id' => $groupId,
                    'scheduled_time' => $this->scheduledTime,
                    'error' => $e->getMessage()
                ]);

                // Retry job for this specific group
                $this->release(300); // Retry after 5 minutes
            }
        }

        // Update post status after all groups processed
        $this->updatePostStatus();
    }

    private function updatePostStatus()
    {
        $totalScheduled = $this->post->total_scheduled ?? (count($this->post->group_ids ?? []) * count($this->post->schedule_times ?? []));
        $sentCount = $this->post->logs()->where('status', 'sent')->count();
        $failedCount = $this->post->logs()->where('status', 'failed')->count();

        if ($sentCount === $totalScheduled) {
            $this->post->update(['status' => 'completed']);
        } else if ($sentCount > 0) {
            $this->post->update(['status' => 'partially_sent']);
        } else if ($failedCount > 0 && $sentCount === 0) {
            $this->post->update(['status' => 'failed']);
        }
    }

    public function failed(\Throwable $exception)
    {
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