<?php
// app/Console/Commands/ProcessScheduledMessages.php - Scalable for 10k+ messages/day

namespace App\Console\Commands;

use App\Models\ScheduledPost;
use App\Models\Group;
use App\Models\PostLog;
use App\Jobs\SendTelegramMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Carbon\Carbon;

class ProcessScheduledMessages extends Command
{
    protected $signature = 'messages:process-scheduled 
                           {--dry-run : Show what would be processed}
                           {--debug : Show detailed output}
                           {--batch-size=100 : Messages per batch}
                           {--max-dispatches=500 : Max jobs per run}';
    
    protected $description = 'Scalable scheduler for 10k+ messages/day with rate limiting';

    public function handle()
    {
        $startTime = microtime(true);
        $dryRun = $this->option('dry-run');
        $debug = $this->option('debug');
        $batchSize = (int) $this->option('batch-size');
        $maxDispatches = (int) $this->option('max-dispatches');
        
        // Prevent overlapping runs
        $lockKey = 'process_scheduled_messages';
        if (!Cache::lock($lockKey, 300)->get()) {
            $this->warn('Another instance is running. Skipping.');
            return 1;
        }

        try {
            $this->info('🚀 Scalable Message Scheduler (10k+/day capable)');
            $this->info('================================================');
            
            if ($dryRun) {
                $this->warn('DRY RUN MODE');
            }

            $nowUtc = Carbon::now('UTC');
            $this->info("Current UTC: {$nowUtc->format('Y-m-d H:i:s')}");

            // Define processing window (±5 minutes for reliability)
            $startWindow = $nowUtc->copy()->subMinutes(5);
            $endWindow = $nowUtc->copy()->addMinutes(5);
            
            $this->info("Processing window: {$startWindow->format('H:i')} to {$endWindow->format('H:i')}");

            $totalDispatched = 0;
            $totalProcessed = 0;
            $totalSkipped = 0;

            // Get posts in batches for memory efficiency
            ScheduledPost::chunk($batchSize, function ($posts) use (
                $startWindow, $endWindow, $nowUtc, &$totalDispatched, &$totalProcessed, 
                &$totalSkipped, $maxDispatches, $dryRun, $debug
            ) {
                foreach ($posts as $post) {
                    if ($totalDispatched >= $maxDispatches) {
                        $this->warn('Max dispatches reached. Stopping.');
                        return false; // Stop processing
                    }

                    $result = $this->processPost($post, $startWindow, $endWindow, $nowUtc, $dryRun, $debug);
                    
                    $totalProcessed++;
                    $totalDispatched += $result['dispatched'];
                    $totalSkipped += $result['skipped'];
                }
                
                return true; // Continue processing
            });

            $processingTime = round((microtime(true) - $startTime) * 1000, 2);
            
            $this->info('');
            $this->info('📊 PROCESSING SUMMARY:');
            $this->info("Posts processed: {$totalProcessed}");
            $this->info("Jobs dispatched: {$totalDispatched}");
            $this->info("Messages skipped: {$totalSkipped}");
            $this->info("Processing time: {$processingTime}ms");
            
            // Queue health check
            $this->checkQueueHealth();
            
            if ($totalDispatched > 0) {
                $this->info("✅ {$totalDispatched} messages queued for sending");
                $this->info("💡 Ensure queue workers are running: php artisan queue:work");
            }

            return 0;

        } finally {
            Cache::lock($lockKey)->release();
        }
    }

    private function processPost($post, $startWindow, $endWindow, $nowUtc, $dryRun, $debug)
    {
        $dispatched = 0;
        $skipped = 0;
        
        $groupIds = $post->group_ids ?? [];
        $utcTimes = $post->schedule_times_utc ?? [];
        $userTimes = $post->schedule_times ?? [];

        if (empty($groupIds) || empty($utcTimes)) {
            return ['dispatched' => 0, 'skipped' => 1];
        }

        // Fix missing UTC times if needed
        if (count($utcTimes) !== count($userTimes) && $post->user_timezone) {
            $utcTimes = $this->regenerateUtcTimes($userTimes, $post->user_timezone);
            $post->schedule_times_utc = $utcTimes;
            $post->save();
        }

        if ($debug) {
            $this->line("Post {$post->id}: " . count($utcTimes) . " times, " . count($groupIds) . " groups");
        }

        foreach ($utcTimes as $index => $utcTimeString) {
            $userTime = $userTimes[$index] ?? $utcTimeString;
            
            try {
                $scheduledUtc = Carbon::parse($utcTimeString, 'UTC');
                
                // Check if time is within processing window
                if (!$scheduledUtc->between($startWindow, $endWindow)) {
                    continue;
                }

                if ($debug) {
                    $diffMinutes = $nowUtc->diffInMinutes($scheduledUtc, false);
                    $this->line("  Due time: {$utcTimeString} ({$diffMinutes} min)");
                }

                foreach ($groupIds as $groupId) {
                    // Skip if already processed
                    if ($this->isAlreadyProcessed($post->id, $groupId, $userTime)) {
                        $skipped++;
                        continue;
                    }

                    if (!$dryRun) {
                        // Dispatch job with priority and delay for rate limiting
                        $delay = $this->calculateDelay($dispatched);
                        
                        SendTelegramMessage::dispatch(
                            $post->id,
                            $groupId,
                            $userTime,
                            $post->content,
                            $scheduledUtc->toDateTimeString()
                        )
                        ->delay(now()->addSeconds($delay))
                        ->onQueue($this->getOptimalQueue());
                        
                        $dispatched++;
                        
                        if ($debug) {
                            $this->line("    → Queued for group {$groupId} (delay: {$delay}s)");
                        }
                    } else {
                        $dispatched++;
                        if ($debug) {
                            $this->line("    → [DRY RUN] Would queue for group {$groupId}");
                        }
                    }
                }

            } catch (\Exception $e) {
                $this->error("Error processing time {$utcTimeString}: " . $e->getMessage());
            }
        }

        return ['dispatched' => $dispatched, 'skipped' => $skipped];
    }

    private function regenerateUtcTimes($userTimes, $userTimezone)
    {
        $utcTimes = [];
        
        foreach ($userTimes as $userTime) {
            try {
                $userCarbon = Carbon::parse($userTime, $userTimezone);
                $utcTimes[] = $userCarbon->utc()->format('Y-m-d H:i:s');
            } catch (\Exception $e) {
                $this->error("Error converting time {$userTime}: " . $e->getMessage());
                $utcTimes[] = $userTime;
            }
        }
        
        return $utcTimes;
    }

    private function isAlreadyProcessed($postId, $groupId, $scheduledTime)
    {
        return PostLog::where('post_id', $postId)
            ->where('group_id', $groupId)
            ->where('scheduled_time', $scheduledTime)
            ->where('status', 'sent')
            ->exists();
    }

    private function calculateDelay($dispatchCount)
    {
        // Progressive delay to respect Telegram rate limits
        // Telegram allows ~30 messages/second, so we spread them out
        
        if ($dispatchCount < 30) {
            return 0; // First 30 messages: immediate
        } elseif ($dispatchCount < 100) {
            return 1; // Next 70: 1 second delay
        } elseif ($dispatchCount < 300) {
            return 2; // Next 200: 2 second delay
        } else {
            return 3; // Rest: 3 second delay
        }
    }

    private function getOptimalQueue()
    {
        // Distribute across multiple queues for parallel processing
        $queues = [
            'telegram-high',
            'telegram-medium-1', 
            'telegram-medium-2',
            'telegram-low'
        ];
        
        // Simple round-robin selection
        static $queueIndex = 0;
        $queue = $queues[$queueIndex % count($queues)];
        $queueIndex++;
        
        return $queue;
    }

    private function checkQueueHealth()
    {
        $this->info('');
        $this->info('📈 QUEUE HEALTH CHECK:');
        
        try {
            $redis = Redis::connection();
            
            $queues = ['telegram-high', 'telegram-medium-1', 'telegram-medium-2', 'telegram-low'];
            $totalQueued = 0;
            
            foreach ($queues as $queue) {
                $size = $redis->llen("queues:{$queue}");
                $totalQueued += $size;
                
                if ($size > 0) {
                    $this->line("Queue {$queue}: {$size} jobs");
                }
            }
            
            $this->info("Total queued jobs: {$totalQueued}");
            
            if ($totalQueued > 1000) {
                $this->warn('⚠️  High queue backlog detected. Consider adding more workers.');
            }
            
            // Check failed jobs
            $failedJobs = DB::table('failed_jobs')->count();
            if ($failedJobs > 0) {
                $this->warn("Failed jobs: {$failedJobs}");
            }
            
        } catch (\Exception $e) {
            $this->error("Queue health check failed: " . $e->getMessage());
        }
    }
}