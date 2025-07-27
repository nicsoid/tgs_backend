<?php
// app/Console/Commands/ProcessScheduledPosts.php - FIXED without verbose conflict

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
                           {--force : Force process all times in the past 24 hours}
                           {--debug : Show detailed output}';
    
    protected $description = 'Process scheduled posts - FIXED TIME LOGIC NO CONFLICTS';

    public function handle()
    {
        $startTime = microtime(true);
        $batchSize = (int) $this->option('batch-size');
        $maxJobs = (int) $this->option('max-jobs');
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');
        $debug = $this->option('debug');

        // Prevent overlapping runs
        $lockKey = 'process_scheduled_posts';
        $lock = Cache::lock($lockKey, 300);

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
            
            // MUCH MORE GENEROUS time windows
            if ($force) {
                $pastCutoff = $now->copy()->subHours(24);
                $futureCutoff = $now->copy()->addHours(6); // 6 hours future!
                $this->info('FORCE MODE: Processing 24 hours past to 6 hours future');
            } else {
                $pastCutoff = $now->copy()->subHours(3);
                $futureCutoff = $now->copy()->addHours(3); // 3 hours future!
                $this->info('NORMAL MODE: Processing 3 hours past to 3 hours future');
            }

            $this->info("Current UTC time: {$now->format('Y-m-d H:i:s')}");
            $this->info("Processing window: {$pastCutoff->format('Y-m-d H:i:s')} to {$futureCutoff->format('Y-m-d H:i:s')}");

            $totalDispatched = 0;
            
            // Get posts that should be processed
            $posts = ScheduledPost::whereIn('status', ['pending', 'partially_sent'])->get();
            
            if ($posts->isEmpty()) {
                $this->warn('No pending posts found!');
                return 0;
            }
            
            $this->info("Found {$posts->count()} posts to check");

            foreach ($posts as $post) {
                if ($totalDispatched >= $maxJobs) {
                    $this->warn('Maximum job limit reached. Stopping.');
                    break;
                }

                $dispatched = $this->processPost($post, $now, $pastCutoff, $futureCutoff, $dryRun, $debug);
                $totalDispatched += $dispatched;
            }

            $processingTime = round((microtime(true) - $startTime) * 1000, 2);
            $this->info("Processing completed in {$processingTime}ms");
            $this->info("Total jobs dispatched: {$totalDispatched}");

            if ($totalDispatched === 0) {
                $this->warn('⚠️  NO JOBS DISPATCHED!');
                $this->info('Times are outside processing window. Use --force to process future times.');
            } else {
                $this->info("✅ {$totalDispatched} messages queued for sending!");
            }

            return 0;

        } finally {
            $lock->release();
        }
    }

    private function processPost($post, $now, $pastCutoff, $futureCutoff, $dryRun, $debug)
    {
        $dispatched = 0;
        $groupIds = $post->group_ids ?? [];
        $scheduleTimesUtc = $post->schedule_times_utc ?? [];
        $scheduleTimesUser = $post->schedule_times ?? [];

        // Validation
        if (empty($groupIds)) {
            if ($debug) $this->warn("Post {$post->id}: No groups");
            return 0;
        }

        if (empty($scheduleTimesUtc)) {
            if ($debug) $this->warn("Post {$post->id}: No UTC schedule times");
            return 0;
        }

        if ($debug) {
            $this->info("Processing Post {$post->id}: {$post->status}, " . count($scheduleTimesUtc) . " times, " . count($groupIds) . " groups");
        }

        foreach ($scheduleTimesUtc as $index => $scheduledTimeUtc) {
            try {
                $scheduledUtc = Carbon::parse($scheduledTimeUtc, 'UTC');
                $originalScheduleTime = $scheduleTimesUser[$index] ?? $scheduledTimeUtc;
                
                // FIXED: Much more generous time window
                $isInWindow = $scheduledUtc->gte($pastCutoff) && $scheduledUtc->lte($futureCutoff);
                
                if ($debug) {
                    $minutesFromNow = $now->diffInMinutes($scheduledUtc, false);
                    $this->line("  Time {$index}: {$scheduledTimeUtc} ({$minutesFromNow} min) - " . 
                              ($isInWindow ? 'IN WINDOW ✅' : 'OUTSIDE WINDOW ❌'));
                }
                
                if ($isInWindow) {
                    foreach ($groupIds as $groupId) {
                        // Check for duplicates
                        if (!$this->isAlreadyProcessed($post->id, $groupId, $originalScheduleTime)) {
                            if (!$dryRun) {
                                // Dispatch with small random delay
                                SendScheduledPost::dispatch(
                                    $post->id,
                                    $originalScheduleTime,
                                    $groupId
                                )->delay(now()->addSeconds(rand(1, 10)));
                            }

                            $dispatched++;
                            
                            if ($debug) {
                                $this->line("    → " . ($dryRun ? 'WOULD DISPATCH' : 'DISPATCHED') . 
                                          ": Post {$post->id} to Group {$groupId}");
                            }
                        } else {
                            if ($debug) {
                                $this->line("    → ALREADY SENT: Post {$post->id} to Group {$groupId}");
                            }
                        }
                    }
                }
            } catch (\Exception $e) {
                $this->error("Error processing time {$scheduledTimeUtc}: " . $e->getMessage());
            }
        }

        return $dispatched;
    }

    private function isAlreadyProcessed($postId, $groupId, $scheduledTime): bool
    {
        return DB::connection('mongodb')
            ->table('post_logs')
            ->where('post_id', $postId)
            ->where('group_id', $groupId)
            ->where('scheduled_time', $scheduledTime)
            ->where('status', 'sent')
            ->exists();
    }
}
