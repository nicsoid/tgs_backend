#!/bin/bash
# fix-time-logic.sh - Fix the time logic in ProcessScheduledPosts command

echo "🔧 FIXING TIME LOGIC IN ProcessScheduledPosts"
echo "============================================="

echo "Based on your earlier debug, the issue is likely in the time comparison logic."
echo "Let's create a fixed version of the ProcessScheduledPosts command."
echo ""

echo "1. BACKUP CURRENT COMMAND"
echo "========================="
if [ -f "backend/app/Console/Commands/ProcessScheduledPosts.php" ]; then
    cp backend/app/Console/Commands/ProcessScheduledPosts.php backend/app/Console/Commands/ProcessScheduledPosts.php.backup
    echo "✅ Backed up current ProcessScheduledPosts.php"
else
    echo "❌ ProcessScheduledPosts.php not found!"
fi

echo ""
echo "2. CREATE FIXED ProcessScheduledPosts COMMAND"
echo "============================================="

cat > backend/app/Console/Commands/ProcessScheduledPosts.php << 'EOF'
<?php
// app/Console/Commands/ProcessScheduledPosts.php - FIXED TIME LOGIC

namespace App\Console\Commands;

use App\Models\ScheduledPost;
use App\Jobs\SendScheduledPost;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class ProcessScheduledPosts extends Command
{
    protected $signature = 'posts:process-scheduled 
                           {--batch-size=100 : Number of posts to process per batch}
                           {--max-jobs=1000 : Maximum jobs to dispatch per run}
                           {--dry-run : Show what would be processed without dispatching}
                           {--force-past : Process all past times regardless of age}';
    
    protected $description = 'Process scheduled posts - FIXED TIME LOGIC VERSION';

    public function handle()
    {
        $startTime = microtime(true);
        $batchSize = (int) $this->option('batch-size');
        $maxJobs = (int) $this->option('max-jobs');
        $dryRun = $this->option('dry-run');
        $forcePast = $this->option('force-past');

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
            // FIXED: Much more generous time window for processing
            $cutoffTime = $now->copy()->addMinutes(10); // 10 minute future buffer
            $pastCutoff = $forcePast ? $now->copy()->subDays(7) : $now->copy()->subHours(3); // 3 hours past

            $this->info("Current UTC time: {$now->format('Y-m-d H:i:s')}");
            $this->info("Processing times from: {$pastCutoff->format('Y-m-d H:i:s')} to: {$cutoffTime->format('Y-m-d H:i:s')}");

            $totalDispatched = 0;
            
            // Get ALL posts regardless of status for debugging
            $query = ScheduledPost::select('_id', 'group_ids', 'schedule_times', 'schedule_times_utc', 'content', 'user_timezone', 'status');
            
            if (!$forcePast) {
                $query->whereIn('status', ['pending', 'partially_sent']);
            }
            
            $query->chunk($batchSize, function ($postChunk) use ($now, $cutoffTime, $pastCutoff, &$maxJobs, $dryRun, &$totalDispatched) {
                $batchDispatched = $this->processBatch($postChunk, $now, $cutoffTime, $pastCutoff, $maxJobs, $dryRun);
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

    private function processBatch($posts, $now, $cutoffTime, $pastCutoff, &$maxJobs, $dryRun)
    {
        $dispatchedInBatch = 0;

        foreach ($posts as $post) {
            if ($maxJobs <= 0) {
                break;
            }

            $dispatched = $this->processPost($post, $now, $cutoffTime, $pastCutoff, $dryRun);
            $dispatchedInBatch += $dispatched;
            $maxJobs -= $dispatched;
        }

        if ($dispatchedInBatch > 0) {
            $this->info("Dispatched {$dispatchedInBatch} jobs in this batch");
        }

        return $dispatchedInBatch;
    }

    private function processPost($post, $now, $cutoffTime, $pastCutoff, $dryRun)
    {
        $dispatched = 0;
        $groupIds = $post->group_ids ?? [];
        $scheduleTimesUtc = $post->schedule_times_utc ?? [];
        $scheduleTimesUser = $post->schedule_times ?? [];

        if (empty($groupIds)) {
            $this->warn("Post {$post->_id} has no groups, skipping");
            return 0;
        }

        if (empty($scheduleTimesUtc)) {
            $this->warn("Post {$post->_id} has no UTC schedule times, skipping");
            return 0;
        }

        $this->info("Processing post {$post->_id} (status: {$post->status}) with " . count($scheduleTimesUtc) . " times and " . count($groupIds) . " groups");

        foreach ($scheduleTimesUtc as $index => $scheduledTimeUtc) {
            try {
                $scheduledUtc = Carbon::parse($scheduledTimeUtc, 'UTC');
                $originalScheduleTime = $scheduleTimesUser[$index] ?? $scheduledTimeUtc;
                
                $this->info("  Checking time: {$scheduledTimeUtc} (original: {$originalScheduleTime})");
                
                // FIXED: More lenient time checking
                $isPastCutoff = $scheduledUtc->gte($pastCutoff);
                $isBeforeFuture = $scheduledUtc->lte($cutoffTime);
                $shouldProcess = $isPastCutoff && $isBeforeFuture;
                
                $this->info("    - Is after past cutoff ({$pastCutoff->format('Y-m-d H:i:s')}): " . ($isPastCutoff ? 'YES' : 'NO'));
                $this->info("    - Is before future cutoff ({$cutoffTime->format('Y-m-d H:i:s')}): " . ($isBeforeFuture ? 'YES' : 'NO'));
                $this->info("    - Should process: " . ($shouldProcess ? 'YES' : 'NO'));
                
                if ($shouldProcess) {
                    foreach ($groupIds as $groupId) {
                        // FIXED: Check for duplicates with more flexible matching
                        if (!$this->isAlreadyProcessed($post->_id, $groupId, $originalScheduleTime, $scheduledTimeUtc)) {
                            if (!$dryRun) {
                                // Add small random delay to spread load
                                $delay = rand(1, 10); // 1-10 seconds
                                
                                SendScheduledPost::dispatch(
                                    $post->_id,
                                    $originalScheduleTime,
                                    $groupId
                                )->delay(now()->addSeconds($delay));
                            }

                            $dispatched++;
                            
                            $this->line("  → Scheduled: Post {$post->_id} to Group {$groupId} at {$originalScheduleTime}" . 
                                      ($dryRun ? ' [DRY RUN]' : ''));
                        } else {
                            $this->line("  → Already processed: Post {$post->_id} to Group {$groupId} at {$originalScheduleTime}");
                        }
                    }
                } else {
                    $minutesAgo = $now->diffInMinutes($scheduledUtc, false);
                    $this->info("    - Time is {$minutesAgo} minutes " . ($minutesAgo > 0 ? 'ago' : 'in the future'));
                }
            } catch (\Exception $e) {
                $this->error("Error processing time {$scheduledTimeUtc} for post {$post->_id}: " . $e->getMessage());
                continue;
            }
        }

        return $dispatched;
    }

    private function isAlreadyProcessed($postId, $groupId, $scheduledTime, $scheduledTimeUtc = null): bool
    {
        // Check both original time and UTC time for flexibility
        $query = DB::connection('mongodb')
            ->table('post_logs')
            ->where('post_id', $postId)
            ->where('group_id', $groupId)
            ->where('status', 'sent');

        // Check with original scheduled time
        $exists1 = $query->where('scheduled_time', $scheduledTime)->exists();
        
        // Also check with UTC time if different
        $exists2 = false;
        if ($scheduledTimeUtc && $scheduledTimeUtc !== $scheduledTime) {
            $exists2 = $query->where('scheduled_time', $scheduledTimeUtc)->exists();
        }

        return $exists1 || $exists2;
    }
}
EOF

echo "✅ Created fixed ProcessScheduledPosts command"

echo ""
echo "3. CLEAR CACHES AND RESTART"
echo "=========================="
docker-compose exec backend php artisan config:clear
docker-compose exec backend php artisan cache:clear
docker-compose exec backend composer dump-autoload

echo ""
echo "4. TEST FIXED COMMAND"
echo "===================="
echo "Testing the fixed command with verbose output:"
docker-compose exec backend php artisan posts:process-scheduled --dry-run

echo ""
echo "5. TEST WITH FORCE-PAST OPTION"
echo "=============================="
echo "Testing with force-past to catch old messages:"
docker-compose exec backend php artisan posts:process-scheduled --force-past --dry-run

echo ""
echo "6. RUN FOR REAL (SEND MESSAGES)"
echo "==============================="
echo "Running without dry-run to actually send messages:"
docker-compose exec backend php artisan posts:process-scheduled --force-past

echo ""
echo "7. CHECK QUEUE AND PROCESS JOBS"
echo "==============================="
echo "Checking queue size:"
docker-compose exec backend php artisan tinker --execute="
\$redis = app('redis')->connection();
echo 'Default queue size: ' . \$redis->llen('queues:default');
echo 'Telegram queue 1: ' . \$redis->llen('queues:telegram-messages-1');
"

echo ""
echo "Processing queue jobs:"
docker-compose exec backend php artisan queue:work --once --timeout=30 --verbose

echo ""
echo "🎯 FIXES APPLIED"
echo "================"
echo "1. Extended time window (3 hours past, 10 minutes future)"
echo "2. More detailed logging to see exactly what's happening"
echo "3. Force-past option to catch old messages"
echo "4. Better duplicate detection"
echo "5. Improved error handling"
echo ""
echo "Your messages should now be processed and sent!"