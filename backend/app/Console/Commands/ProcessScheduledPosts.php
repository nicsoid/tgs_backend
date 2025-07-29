<?php
// app/Console/Commands/ProcessScheduledPosts.php - TIMEZONE AWARE VERSION

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
                           {--force : Process all times within 24 hours past/future}
                           {--debug : Show detailed timezone debugging}';
    
    protected $description = 'Process scheduled posts - TIMEZONE AWARE VERSION with expanded windows';

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
            $this->info('🌍 Processing scheduled posts with timezone awareness...');
            if ($dryRun) {
                $this->warn('DRY RUN MODE - No jobs will be dispatched');
            }

            $nowUtc = Carbon::now('UTC');
            
            // EXPANDED processing windows
            if ($force) {
                $pastCutoff = $nowUtc->copy()->subHours(24);
                $futureCutoff = $nowUtc->copy()->addHours(24);
                $this->info('FORCE MODE: Processing 24 hours past to 24 hours future');
            } else {
                // More generous normal windows
                $pastCutoff = $nowUtc->copy()->subHours(6);    // 6 hours past
                $futureCutoff = $nowUtc->copy()->addHours(1);  // 1 hour future
                $this->info('NORMAL MODE: Processing 6 hours past to 1 hour future');
            }

            $this->info("Current UTC time: {$nowUtc->format('Y-m-d H:i:s T')}");
            $this->info("Processing window: {$pastCutoff->format('Y-m-d H:i:s')} to {$futureCutoff->format('Y-m-d H:i:s')}");

            $totalDispatched = 0;
            
            // Get posts that should be processed
            //$posts = ScheduledPost::whereIn('status', ['pending', 'partially_sent'])->get();
            $posts = ScheduledPost::get();
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

                $dispatched = $this->processPost($post, $nowUtc, $pastCutoff, $futureCutoff, $dryRun, $debug);
                $totalDispatched += $dispatched;
            }

            $processingTime = round((microtime(true) - $startTime) * 1000, 2);
            $this->info("Processing completed in {$processingTime}ms");
            $this->info("Total jobs dispatched: {$totalDispatched}");

            if ($totalDispatched === 0) {
                $this->warn('⚠️  NO JOBS DISPATCHED!');
                if (!$force) {
                    $this->info('Try using --force to expand the processing window');
                }
            } else {
                $this->info("✅ {$totalDispatched} messages queued for sending!");
            }

            return 0;

        } finally {
            $lock->release();
        }
    }

    private function processPost($post, $nowUtc, $pastCutoff, $futureCutoff, $dryRun, $debug)
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
            // Try to regenerate UTC times if missing
            if (!empty($scheduleTimesUser) && $post->user_timezone) {
                $this->warn("Post {$post->id}: Missing UTC times, regenerating...");
                $newUtcTimes = [];
                
                foreach ($scheduleTimesUser as $userTime) {
                    try {
                        $userCarbon = Carbon::parse($userTime, $post->user_timezone);
                        $newUtcTimes[] = $userCarbon->utc()->format('Y-m-d H:i:s');
                    } catch (\Exception $e) {
                        $this->error("Error converting time {$userTime}: " . $e->getMessage());
                        continue;
                    }
                }
                
                if (!empty($newUtcTimes)) {
                    $post->schedule_times_utc = $newUtcTimes;
                    $post->save();
                    $scheduleTimesUtc = $newUtcTimes;
                    $this->info("✅ Regenerated UTC times for post {$post->id}");
                }
            }
            
            if (empty($scheduleTimesUtc)) {
                if ($debug) $this->warn("Post {$post->id}: No UTC schedule times");
                return 0;
            }
        }

        if ($debug) {
            $this->info("Processing Post {$post->id}: {$post->status}");
            $this->info("  User timezone: " . ($post->user_timezone ?? 'None'));
            $this->info("  Groups: " . count($groupIds));
            $this->info("  Schedule times: " . count($scheduleTimesUtc));
        }

        foreach ($scheduleTimesUtc as $index => $scheduledTimeUtc) {
            try {
                $scheduledUtc = Carbon::parse($scheduledTimeUtc, 'UTC');
                $originalScheduleTime = $scheduleTimesUser[$index] ?? $scheduledTimeUtc;
                
                // More generous time window checking
                $isInWindow = $scheduledUtc->gte($pastCutoff) && $scheduledUtc->lte($futureCutoff);
                
                if ($debug) {
                    $minutesFromNow = $nowUtc->diffInMinutes($scheduledUtc, false);
                    $this->line("  Time {$index}: {$originalScheduleTime} → {$scheduledTimeUtc}");
                    $this->line("    Difference: {$minutesFromNow} minutes " . ($minutesFromNow > 0 ? 'ago' : 'from now'));
                    $this->line("    In window: " . ($isInWindow ? 'YES ✅' : 'NO ❌'));
                    
                    if ($post->user_timezone && $post->user_timezone !== 'UTC') {
                        try {
                            $userNow = Carbon::now($post->user_timezone);
                            $scheduledInUserTz = Carbon::parse($originalScheduleTime, $post->user_timezone);
                            $userDiff = $userNow->diffInMinutes($scheduledInUserTz, false);
                            $this->line("    User perspective: {$userDiff} minutes " . ($userDiff > 0 ? 'ago' : 'from now') . " in {$post->user_timezone}");
                        } catch (\Exception $e) {
                            $this->line("    User timezone error: " . $e->getMessage());
                        }
                    }
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