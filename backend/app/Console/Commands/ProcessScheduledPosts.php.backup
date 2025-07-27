<?php
// app/Console/Commands/ProcessScheduledPosts.php - Updated Version

namespace App\Console\Commands;

use App\Models\ScheduledPost;
use App\Jobs\SendScheduledPost;
use App\Services\TimezoneService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class ProcessScheduledPosts extends Command
{
    protected $signature = 'posts:process-scheduled 
                           {--batch-size=100 : Number of posts to process per batch}
                           {--max-jobs=1000 : Maximum jobs to dispatch per run}
                           {--dry-run : Show what would be processed without dispatching}';
    
    protected $description = 'Process scheduled posts - always check for due times regardless of status';

    public function handle()
    {
        $startTime = microtime(true);
        $batchSize = (int) $this->option('batch-size');
        $maxJobs = (int) $this->option('max-jobs');
        $dryRun = $this->option('dry-run');

        // Prevent overlapping runs
        $lockKey = 'process_scheduled_posts';
        $lock = Cache::lock($lockKey, 300); // 5 minutes

        if (!$lock->get()) {
            $this->warn('Another instance is already processing. Skipping.');
            return 1;
        }

        try {
            $this->info('Processing scheduled posts...');
            if ($dryRun) {
                $this->warn('DRY RUN MODE - No jobs will be dispatched');
            }

            $now = Carbon::now('UTC');
            $cutoffTime = $now->copy()->addMinutes(2); // Small buffer for processing delays

            $this->info("Current UTC time: {$now->format('Y-m-d H:i:s')}");
            $this->info("Processing times up to: {$cutoffTime->format('Y-m-d H:i:s')}");

            // Get ALL posts with schedule times - ignore status
            $totalDispatched = 0;
            
            ScheduledPost::select('_id', 'group_ids', 'schedule_times', 'schedule_times_utc', 'content', 'user_timezone')
                ->chunk($batchSize, function ($postChunk) use ($now, $cutoffTime, &$maxJobs, $dryRun, &$totalDispatched) {
                    $batchDispatched = $this->processBatch($postChunk, $now, $cutoffTime, $maxJobs, $dryRun);
                    $totalDispatched += $batchDispatched;
                    $maxJobs -= $batchDispatched;
                    
                    if ($maxJobs <= 0) {
                        $this->warn('Maximum job limit reached. Stopping.');
                        return false; // Stop chunk processing
                    }
                    
                    return true; // Continue processing
                });

            $processingTime = round((microtime(true) - $startTime) * 1000, 2);
            $this->info("Processing completed in {$processingTime}ms");
            $this->info("Total jobs dispatched: {$totalDispatched}");

            return 0;

        } finally {
            $lock->release();
        }
    }

    private function processBatch($posts, $now, $cutoffTime, &$maxJobs, $dryRun)
    {
        $dispatchedInBatch = 0;

        foreach ($posts as $post) {
            if ($maxJobs <= 0) {
                break;
            }

            $dispatched = $this->processPost($post, $now, $cutoffTime, $dryRun);
            $dispatchedInBatch += $dispatched;
            $maxJobs -= $dispatched;
        }

        if ($dispatchedInBatch > 0) {
            $this->info("Dispatched {$dispatchedInBatch} jobs in this batch");
        }

        return $dispatchedInBatch;
    }

    private function processPost($post, $now, $cutoffTime, $dryRun)
    {
        $dispatched = 0;
        $groupIds = $post->group_ids ?? [];
        $scheduleTimesUtc = $post->schedule_times_utc ?? [];
        $scheduleTimesUser = $post->schedule_times ?? [];

        if (empty($groupIds) || empty($scheduleTimesUtc)) {
            return 0;
        }

        foreach ($scheduleTimesUtc as $index => $scheduledTimeUtc) {
            try {
                $scheduledUtc = Carbon::parse($scheduledTimeUtc, 'UTC');
                
                // Check if this time has passed (with buffer) and is not too far in the past
                if ($scheduledUtc->lte($cutoffTime) && $scheduledUtc->gte($now->copy()->subHours(1))) {
                    $originalScheduleTime = $scheduleTimesUser[$index] ?? $scheduledTimeUtc;
                    
                    foreach ($groupIds as $groupId) {
                        // Efficient duplicate check - prevent sending same message twice
                        if (!$this->isAlreadyProcessed($post->_id, $groupId, $originalScheduleTime)) {
                            if (!$dryRun) {
                                // Add small random delay to spread load
                                $delay = rand(1, 30); // 1-30 seconds
                                
                                SendScheduledPost::dispatch(
                                    $post->_id,
                                    $originalScheduleTime,
                                    $groupId
                                )->delay(now()->addSeconds($delay));
                            }

                            $dispatched++;
                            
                            $this->line("  → Scheduled: Post {$post->_id} to Group {$groupId} at {$originalScheduleTime}" . 
                                      ($dryRun ? ' [DRY RUN]' : ''));
                        }
                    }
                }
            } catch (\Exception $e) {
                $this->error("Error processing time {$scheduledTimeUtc} for post {$post->_id}: " . $e->getMessage());
                continue;
            }
        }

        return $dispatched;
    }

    private function isAlreadyProcessed($postId, $groupId, $scheduledTime): bool
    {
        // Use efficient index-based query to check if already sent
        return DB::connection('mongodb')
            ->table('post_logs')
            ->where('post_id', $postId)
            ->where('group_id', $groupId)
            ->where('scheduled_time', $scheduledTime)
            ->where('status', 'sent')
            ->exists();
    }
}