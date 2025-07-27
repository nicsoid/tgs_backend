#!/bin/bash
# complete-time-logic-fix.sh - Comprehensive fix for message scheduling

echo "🔍 COMPLETE TIME LOGIC ANALYSIS AND FIX"
echo "======================================="

echo "The issue: Commands run but dispatch 0 jobs = time selection logic is broken"
echo ""

echo "1. ANALYZE CURRENT DATABASE STATE"
echo "================================="
docker-compose exec backend php artisan tinker --execute="
use App\Models\ScheduledPost;
use Carbon\Carbon;

echo '=== DATABASE ANALYSIS ===' . PHP_EOL;
echo 'Current UTC time: ' . Carbon::now('UTC')->format('Y-m-d H:i:s T') . PHP_EOL;
echo 'Current local time: ' . Carbon::now()->format('Y-m-d H:i:s T') . PHP_EOL;
echo 'Laravel timezone: ' . config('app.timezone') . PHP_EOL;
echo 'PHP timezone: ' . date_default_timezone_get() . PHP_EOL;
echo '' . PHP_EOL;

\$posts = ScheduledPost::where('status', 'pending')->get();
echo 'Total pending posts: ' . \$posts->count() . PHP_EOL . PHP_EOL;

foreach (\$posts as \$post) {
    echo '=== POST: ' . \$post->id . ' ===' . PHP_EOL;
    echo 'Status: ' . \$post->status . PHP_EOL;
    echo 'User timezone: ' . (\$post->user_timezone ?? 'NONE') . PHP_EOL;
    echo 'Groups: ' . count(\$post->group_ids ?? []) . PHP_EOL;
    
    // Check schedule times arrays
    \$userTimes = \$post->schedule_times ?? [];
    \$utcTimes = \$post->schedule_times_utc ?? [];
    
    echo 'User schedule times count: ' . count(\$userTimes) . PHP_EOL;
    echo 'UTC schedule times count: ' . count(\$utcTimes) . PHP_EOL;
    
    if (empty(\$userTimes)) {
        echo '❌ CRITICAL: No user schedule times!' . PHP_EOL;
    }
    
    if (empty(\$utcTimes)) {
        echo '❌ CRITICAL: No UTC schedule times!' . PHP_EOL;
    }
    
    // Show first few times
    echo 'User times (first 3):' . PHP_EOL;
    foreach (array_slice(\$userTimes, 0, 3) as \$i => \$time) {
        echo '  [' . \$i . '] ' . \$time . PHP_EOL;
    }
    
    echo 'UTC times (first 3):' . PHP_EOL;
    foreach (array_slice(\$utcTimes, 0, 3) as \$i => \$time) {
        echo '  [' . \$i . '] ' . \$time . PHP_EOL;
    }
    
    // Check if any times are processable NOW
    \$now = Carbon::now('UTC');
    \$processableCount = 0;
    
    foreach (\$utcTimes as \$timeUtc) {
        try {
            \$scheduledUtc = Carbon::parse(\$timeUtc, 'UTC');
            
            // Current logic in ProcessScheduledPosts
            if (\$scheduledUtc->lte(\$now->copy()->addMinutes(2)) && 
                \$scheduledUtc->gte(\$now->copy()->subHours(1))) {
                \$processableCount++;
            }
        } catch (Exception \$e) {
            echo '❌ Error parsing time: ' . \$timeUtc . ' - ' . \$e->getMessage() . PHP_EOL;
        }
    }
    
    echo 'Processable times RIGHT NOW: ' . \$processableCount . PHP_EOL;
    echo '---' . PHP_EOL;
}
"

echo ""
echo "2. TEST TIMEZONE CONVERSION ISSUES"
echo "=================================="
docker-compose exec backend php artisan tinker --execute="
use Carbon\Carbon;

echo '=== TIMEZONE CONVERSION TEST ===' . PHP_EOL;

// Test common timezone conversions
\$testCases = [
    ['America/Mexico_City', '2025-07-27T15:23'],
    ['America/New_York', '2025-07-27T14:23'],
    ['Europe/London', '2025-07-27T19:23'],
    ['UTC', '2025-07-27T18:23']
];

foreach (\$testCases as [\$timezone, \$time]) {
    echo 'Testing: ' . \$time . ' (' . \$timezone . ')' . PHP_EOL;
    
    try {
        \$carbonTime = Carbon::parse(\$time, \$timezone);
        \$utcTime = \$carbonTime->utc();
        \$now = Carbon::now('UTC');
        
        echo '  Parsed: ' . \$carbonTime->format('Y-m-d H:i:s T') . PHP_EOL;
        echo '  UTC: ' . \$utcTime->format('Y-m-d H:i:s T') . PHP_EOL;
        echo '  Minutes from now: ' . \$now->diffInMinutes(\$utcTime, false) . PHP_EOL;
        echo '  Is future: ' . (\$utcTime->isFuture() ? 'YES' : 'NO') . PHP_EOL;
        echo '' . PHP_EOL;
    } catch (Exception \$e) {
        echo '  ERROR: ' . \$e->getMessage() . PHP_EOL;
    }
}
"

echo ""
echo "3. CREATE DIAGNOSTIC COMMAND"
echo "============================"

cat > backend/app/Console/Commands/DiagnoseScheduling.php << 'EOF'
<?php
// app/Console/Commands/DiagnoseScheduling.php - Detailed scheduling diagnosis

namespace App\Console\Commands;

use App\Models\ScheduledPost;
use App\Models\PostLog;
use App\Models\Group;
use Illuminate\Console\Command;
use Carbon\Carbon;

class DiagnoseScheduling extends Command
{
    protected $signature = 'schedule:diagnose';
    protected $description = 'Diagnose scheduling issues in detail';

    public function handle()
    {
        $this->info('🔍 DETAILED SCHEDULING DIAGNOSIS');
        $this->info('================================');
        
        $now = Carbon::now('UTC');
        $this->info("Current UTC time: {$now->format('Y-m-d H:i:s T')}");
        $this->info("Current local time: " . Carbon::now()->format('Y-m-d H:i:s T'));
        $this->info("Laravel timezone: " . config('app.timezone'));
        $this->info("PHP timezone: " . date_default_timezone_get());
        $this->line('');

        // Get all posts
        $posts = ScheduledPost::all();
        $this->info("Total posts in database: {$posts->count()}");
        
        $pendingPosts = $posts->where('status', 'pending');
        $this->info("Pending posts: {$pendingPosts->count()}");
        $this->line('');

        if ($pendingPosts->isEmpty()) {
            $this->error('❌ NO PENDING POSTS FOUND!');
            $this->info('This explains why no jobs are dispatched.');
            $this->info('Create a test post with near-future times.');
            return;
        }

        foreach ($pendingPosts as $post) {
            $this->info("=== POST {$post->id} ===");
            $this->info("Status: {$post->status}");
            $this->info("User timezone: " . ($post->user_timezone ?? 'NONE'));
            
            $groupIds = $post->group_ids ?? [];
            $this->info("Groups: " . count($groupIds));
            
            // Validate groups exist
            $validGroups = 0;
            foreach ($groupIds as $groupId) {
                if (Group::find($groupId)) {
                    $validGroups++;
                }
            }
            $this->info("Valid groups: {$validGroups}/" . count($groupIds));
            
            $userTimes = $post->schedule_times ?? [];
            $utcTimes = $post->schedule_times_utc ?? [];
            
            $this->info("User schedule times: " . count($userTimes));
            $this->info("UTC schedule times: " . count($utcTimes));
            
            if (empty($userTimes) || empty($utcTimes)) {
                $this->error('❌ CRITICAL: Missing schedule times arrays!');
                continue;
            }
            
            // Analyze each time
            $processableNow = 0;
            $futureCount = 0;
            $pastCount = 0;
            
            foreach ($utcTimes as $index => $timeUtc) {
                $userTime = $userTimes[$index] ?? 'N/A';
                
                try {
                    $scheduledUtc = Carbon::parse($timeUtc, 'UTC');
                    $minutesFromNow = $now->diffInMinutes($scheduledUtc, false);
                    
                    if ($scheduledUtc->isFuture()) {
                        $futureCount++;
                    } else {
                        $pastCount++;
                    }
                    
                    // Check ProcessScheduledPosts logic
                    $pastCutoff = $now->copy()->subHours(1);
                    $futureCutoff = $now->copy()->addMinutes(2);
                    
                    if ($scheduledUtc->gte($pastCutoff) && $scheduledUtc->lte($futureCutoff)) {
                        $processableNow++;
                        $this->info("  ✅ PROCESSABLE: {$timeUtc} (user: {$userTime}) - {$minutesFromNow} min");
                    } else {
                        $this->line("  ⏰ WAITING: {$timeUtc} (user: {$userTime}) - {$minutesFromNow} min");
                    }
                    
                } catch (\Exception $e) {
                    $this->error("  ❌ ERROR: {$timeUtc} - " . $e->getMessage());
                }
            }
            
            $this->info("Summary: {$futureCount} future, {$pastCount} past, {$processableNow} processable now");
            
            // Check if already sent
            $sentCount = PostLog::where('post_id', $post->id)->where('status', 'sent')->count();
            $this->info("Already sent: {$sentCount}");
            
            $this->line('---');
        }
        
        // Overall diagnosis
        $this->line('');
        $this->info('🎯 DIAGNOSIS SUMMARY');
        $this->info('===================');
        
        $totalProcessable = 0;
        foreach ($pendingPosts as $post) {
            $now = Carbon::now('UTC');
            $pastCutoff = $now->copy()->subHours(1);
            $futureCutoff = $now->copy()->addMinutes(2);
            
            foreach ($post->schedule_times_utc ?? [] as $timeUtc) {
                try {
                    $scheduledUtc = Carbon::parse($timeUtc, 'UTC');
                    if ($scheduledUtc->gte($pastCutoff) && $scheduledUtc->lte($futureCutoff)) {
                        $totalProcessable++;
                    }
                } catch (\Exception $e) {
                    // Skip invalid times
                }
            }
        }
        
        if ($totalProcessable === 0) {
            $this->error('❌ NO PROCESSABLE TIMES FOUND!');
            $this->info('Possible causes:');
            $this->info('1. All times are too far in the future (>2 minutes)');
            $this->info('2. All times are too old (>1 hour ago)');
            $this->info('3. Timezone conversion is wrong');
            $this->info('4. Schedule times are malformed');
        } else {
            $this->info("✅ Found {$totalProcessable} processable times");
            $this->info('Jobs should be dispatched on next run!');
        }
        
        return 0;
    }
}
EOF

echo "✅ Created diagnostic command"

echo ""
echo "4. RUN DIAGNOSIS"
echo "================"
docker-compose exec backend composer dump-autoload
docker-compose exec backend php artisan schedule:diagnose

echo ""
echo "5. CREATE FIXED PROCESSSCHEDULEDPOSTS COMMAND"
echo "=============================================="

cat > backend/app/Console/Commands/ProcessScheduledPosts.php << 'EOF'
<?php
// app/Console/Commands/ProcessScheduledPosts.php - COMPLETELY FIXED VERSION

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
                           {--verbose : Show detailed output}';
    
    protected $description = 'Process scheduled posts - COMPLETELY FIXED TIME LOGIC';

    public function handle()
    {
        $startTime = microtime(true);
        $batchSize = (int) $this->option('batch-size');
        $maxJobs = (int) $this->option('max-jobs');
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');
        $verbose = $this->option('verbose');

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
            
            // FIXED: More generous time windows
            if ($force) {
                $pastCutoff = $now->copy()->subHours(24);
                $futureCutoff = $now->copy()->addHours(1);
                $this->info('FORCE MODE: Processing 24 hours past to 1 hour future');
            } else {
                $pastCutoff = $now->copy()->subHours(2);
                $futureCutoff = $now->copy()->addMinutes(15);
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

                $dispatched = $this->processPost($post, $now, $pastCutoff, $futureCutoff, $dryRun, $verbose);
                $totalDispatched += $dispatched;
            }

            $processingTime = round((microtime(true) - $startTime) * 1000, 2);
            $this->info("Processing completed in {$processingTime}ms");
            $this->info("Total jobs dispatched: {$totalDispatched}");

            if ($totalDispatched === 0) {
                $this->warn('⚠️  NO JOBS DISPATCHED!');
                $this->info('Run with --verbose to see why, or try --force to process older messages');
            }

            return 0;

        } finally {
            $lock->release();
        }
    }

    private function processPost($post, $now, $pastCutoff, $futureCutoff, $dryRun, $verbose)
    {
        $dispatched = 0;
        $groupIds = $post->group_ids ?? [];
        $scheduleTimesUtc = $post->schedule_times_utc ?? [];
        $scheduleTimesUser = $post->schedule_times ?? [];

        // Validation
        if (empty($groupIds)) {
            if ($verbose) $this->warn("Post {$post->id}: No groups");
            return 0;
        }

        if (empty($scheduleTimesUtc)) {
            if ($verbose) $this->warn("Post {$post->id}: No UTC schedule times");
            return 0;
        }

        if ($verbose) {
            $this->info("Processing Post {$post->id}: {$post->status}, " . count($scheduleTimesUtc) . " times, " . count($groupIds) . " groups");
        }

        foreach ($scheduleTimesUtc as $index => $scheduledTimeUtc) {
            try {
                $scheduledUtc = Carbon::parse($scheduledTimeUtc, 'UTC');
                $originalScheduleTime = $scheduleTimesUser[$index] ?? $scheduledTimeUtc;
                
                // FIXED: Better time window checking
                $isInWindow = $scheduledUtc->gte($pastCutoff) && $scheduledUtc->lte($futureCutoff);
                
                if ($verbose) {
                    $minutesFromNow = $now->diffInMinutes($scheduledUtc, false);
                    $this->line("  Time {$index}: {$scheduledTimeUtc} ({$minutesFromNow} min) - " . 
                              ($isInWindow ? 'IN WINDOW' : 'OUTSIDE WINDOW'));
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
                                )->delay(now()->addSeconds(rand(1, 30)));
                            }

                            $dispatched++;
                            
                            if ($verbose) {
                                $this->line("    → " . ($dryRun ? 'WOULD DISPATCH' : 'DISPATCHED') . 
                                          ": Post {$post->id} to Group {$groupId}");
                            }
                        } else {
                            if ($verbose) {
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
EOF

echo "✅ Created completely fixed ProcessScheduledPosts command"

echo ""
echo "6. TEST FIXES"
echo "============="
docker-compose exec backend composer dump-autoload
docker-compose exec backend php artisan config:clear

echo ""
echo "Testing diagnostic command:"
docker-compose exec backend php artisan schedule:diagnose

echo ""
echo "Testing fixed process command with verbose output:"
docker-compose exec backend php artisan posts:process-scheduled --dry-run --verbose

echo ""
echo "7. FORCE PROCESS IF NEEDED"
echo "=========================="
echo "If still no processable times, try force mode:"
docker-compose exec backend php artisan posts:process-scheduled --force --dry-run --verbose

echo ""
echo "8. CREATE TEST MESSAGE FOR IMMEDIATE PROCESSING"
echo "==============================================="
docker-compose exec backend php artisan tinker --execute="
use App\Models\ScheduledPost;
use App\Models\User;
use App\Models\Group;
use Carbon\Carbon;

// Find first user and group
\$user = User::first();
\$group = Group::first();

if (\$user && \$group) {
    echo 'Creating test post for immediate processing...' . PHP_EOL;
    
    // Create times: 1 minute ago, now, and 1 minute from now
    \$userTimezone = \$user->getTimezone();
    \$now = Carbon::now(\$userTimezone);
    
    \$times = [
        \$now->copy()->subMinute()->format('Y-m-d\TH:i'),
        \$now->format('Y-m-d\TH:i'),
        \$now->copy()->addMinute()->format('Y-m-d\TH:i')
    ];
    
    \$post = ScheduledPost::create([
        'user_id' => \$user->id,
        'group_ids' => [\$group->id],
        'content' => [
            'text' => '🧪 Test message - ' . now()->format('H:i:s')
        ],
        'schedule_times' => \$times,
        'user_timezone' => \$userTimezone,
        'status' => 'pending'
    ]);
    
    echo 'Created test post: ' . \$post->id . PHP_EOL;
    echo 'Times: ' . implode(', ', \$times) . PHP_EOL;
    echo 'User timezone: ' . \$userTimezone . PHP_EOL;
} else {
    echo 'No user or group found to create test post' . PHP_EOL;
}
"

echo ""
echo "9. FINAL TEST"
echo "============="
echo "Running process command after creating test post:"
docker-compose exec backend php artisan posts:process-scheduled --verbose

echo ""
echo "🎯 COMPREHENSIVE FIX SUMMARY"
echo "============================"
echo "1. ✅ Created diagnostic command to identify issues"
echo "2. ✅ Fixed ProcessScheduledPosts with better time logic"
echo "3. ✅ Added verbose mode for debugging"
echo "4. ✅ Created test post with immediate times"
echo "5. ✅ Expanded time windows for processing"
echo ""
echo "🔍 NEXT STEPS:"
echo "• Run: docker-compose exec backend php artisan schedule:diagnose"
echo "• Check: docker-compose exec backend php artisan posts:process-scheduled --verbose"
echo "• Force: docker-compose exec backend php artisan posts:process-scheduled --force --verbose"
echo ""
echo "📊 If still 0 jobs dispatched, check:"
echo "• No pending posts exist"
echo "• All times are outside processing window"
echo "• Timezone conversion issues"
echo "• Missing schedule_times_utc array"