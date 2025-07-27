#!/bin/bash
# debug-scheduler-step-by-step.sh - Comprehensive debugging

echo "🔍 STEP-BY-STEP SCHEDULER DEBUGGING"
echo "===================================="

echo ""
echo "1. VERIFY COMMAND EXISTS"
echo "========================"
echo "Checking if posts:process-scheduled command is registered:"
docker-compose exec backend php artisan list | grep "posts:"

echo ""
echo "2. TEST SCHEDULE LIST"
echo "===================="
echo "Listing all scheduled commands:"
docker-compose exec backend php artisan schedule:list

echo ""
echo "3. TEST COMMAND DIRECTLY"
echo "========================"
echo "Running posts:process-scheduled command directly:"
docker-compose exec backend php artisan posts:process-scheduled --dry-run

if [ $? -ne 0 ]; then
    echo "❌ Command failed! Let's check if it exists..."
    echo ""
    echo "Checking Commands directory:"
    docker-compose exec backend ls -la app/Console/Commands/ | grep -i process
    
    echo ""
    echo "All available commands:"
    docker-compose exec backend php artisan list
fi

echo ""
echo "4. CHECK DATABASE STATE"
echo "======================="
echo "Checking for scheduled posts that should be processed:"
docker-compose exec backend php artisan tinker --execute="
use App\Models\ScheduledPost;
use Carbon\Carbon;

echo 'Total scheduled posts: ' . ScheduledPost::count();
echo 'Pending posts: ' . ScheduledPost::where('status', 'pending')->count();

\$pendingPosts = ScheduledPost::where('status', 'pending')->get();
echo 'Details of pending posts:';
foreach (\$pendingPosts as \$post) {
    echo '  Post ID: ' . \$post->id;
    echo '  Status: ' . \$post->status;
    echo '  Groups: ' . count(\$post->group_ids ?? []);
    echo '  User timezone: ' . (\$post->user_timezone ?? 'UTC');
    echo '  Schedule times (user): ' . count(\$post->schedule_times ?? []);
    echo '  Schedule times (UTC): ' . count(\$post->schedule_times_utc ?? []);
    
    if (!empty(\$post->schedule_times_utc)) {
        \$now = Carbon::now('UTC');
        \$futureCount = 0;
        \$pastCount = 0;
        foreach (\$post->schedule_times_utc as \$time) {
            \$timeCarbon = Carbon::parse(\$time, 'UTC');
            if (\$timeCarbon->isFuture()) {
                \$futureCount++;
                echo '    Future time: ' . \$time . ' (in ' . \$now->diffForHumans(\$timeCarbon) . ')';
            } else {
                \$pastCount++;
                echo '    Past time: ' . \$time . ' (' . \$timeCarbon->diffForHumans() . ')';
            }
        }
        echo '  Future times: ' . \$futureCount . ', Past times: ' . \$pastCount;
    } else {
        echo '  ❌ No UTC schedule times found!';
    }
    echo '  ---';
}
"

echo ""
echo "5. CHECK SCHEDULER ENVIRONMENT"
echo "============================="
echo "Checking scheduler container environment:"
docker-compose exec scheduler env | grep -E "(APP_|DB_|QUEUE_)"

echo ""
echo "6. MANUAL SCHEDULER RUN WITH VERBOSE OUTPUT"
echo "==========================================="
echo "Running scheduler manually with full verbose output:"
docker-compose exec backend php artisan schedule:run --verbose --no-interaction

echo ""
echo "7. CHECK QUEUE CONNECTION"
echo "========================"
echo "Testing queue connection:"
docker-compose exec backend php artisan tinker --execute="
try {
    \$redis = Redis::connection();
    \$redis->ping();
    echo 'Redis connection: ✅ Working';
    
    // Check queue sizes
    \$defaultQueue = \$redis->llen('queues:default');
    echo 'Default queue size: ' . \$defaultQueue;
    
    \$telegramQueue1 = \$redis->llen('queues:telegram-messages-1');
    echo 'Telegram queue 1 size: ' . \$telegramQueue1;
    
} catch (Exception \$e) {
    echo 'Redis connection: ❌ Failed - ' . \$e->getMessage();
}
"

echo ""
echo "8. CHECK FOR PAST DUE MESSAGES"
echo "=============================="
echo "Looking for messages that should have been sent already:"
docker-compose exec backend php artisan tinker --execute="
use App\Models\ScheduledPost;
use App\Models\PostLog;
use Carbon\Carbon;

\$now = Carbon::now('UTC');
echo 'Current UTC time: ' . \$now->format('Y-m-d H:i:s');

\$posts = ScheduledPost::whereIn('status', ['pending', 'partially_sent'])->get();
\$shouldBeProcessed = 0;

foreach (\$posts as \$post) {
    if (!empty(\$post->schedule_times_utc)) {
        foreach (\$post->schedule_times_utc as \$index => \$timeUtc) {
            \$timeCarbon = Carbon::parse(\$timeUtc, 'UTC');
            \$originalTime = \$post->schedule_times[\$index] ?? \$timeUtc;
            
            // Check if time has passed and within 1 hour (not too old)
            if (\$timeCarbon->lte(\$now) && \$timeCarbon->gte(\$now->copy()->subHours(1))) {
                \$shouldBeProcessed++;
                echo 'Should process: Post ' . \$post->id . ' at ' . \$timeUtc . ' (original: ' . \$originalTime . ')';
                
                // Check if already processed
                foreach (\$post->group_ids ?? [] as \$groupId) {
                    \$alreadySent = PostLog::where('post_id', \$post->id)
                        ->where('group_id', \$groupId)
                        ->where('scheduled_time', \$originalTime)
                        ->where('status', 'sent')
                        ->exists();
                    
                    if (!\$alreadySent) {
                        echo '  -> Group ' . \$groupId . ': Not sent yet ⏳';
                    } else {
                        echo '  -> Group ' . \$groupId . ': Already sent ✅';
                    }
                }
            }
        }
    }
}

echo 'Total messages that should be processed: ' . \$shouldBeProcessed;
"

echo ""
echo "9. TEST MANUAL JOB DISPATCH"
echo "==========================="
echo "Testing if we can manually dispatch a job:"
docker-compose exec backend php artisan tinker --execute="
use App\Jobs\SendScheduledPost;
use App\Models\ScheduledPost;

\$posts = ScheduledPost::where('status', 'pending')->first();
if (\$posts && !empty(\$posts->group_ids) && !empty(\$posts->schedule_times)) {
    echo 'Testing with Post ID: ' . \$posts->id;
    try {
        SendScheduledPost::dispatch(\$posts->id, \$posts->schedule_times[0], \$posts->group_ids[0]);
        echo 'Job dispatched successfully! ✅';
    } catch (Exception \$e) {
        echo 'Job dispatch failed: ❌ ' . \$e->getMessage();
    }
} else {
    echo 'No suitable posts found for testing';
}
"

echo ""
echo "10. CHECK CACHE CONFIGURATION"
echo "============================="
echo "Checking cache configuration (needed for withoutOverlapping):"
docker-compose exec backend php artisan tinker --execute="
echo 'Default cache driver: ' . config('cache.default');
try {
    Cache::put('test_key', 'test_value', 60);
    \$value = Cache::get('test_key');
    if (\$value === 'test_value') {
        echo 'Cache working: ✅';
    } else {
        echo 'Cache not working properly: ❌';
    }
} catch (Exception \$e) {
    echo 'Cache error: ❌ ' . \$e->getMessage();
}
"

echo ""
echo "🎯 SUMMARY & NEXT STEPS"
echo "======================="
echo "Based on the output above:"
echo ""
echo "1. If 'posts:process-scheduled' command is NOT listed → Command file missing"
echo "2. If command exists but schedule:list is empty → Kernel.php not loading properly"
echo "3. If posts exist but no future times → Timezone conversion issue"
echo "4. If everything looks good but still not working → Queue/Redis issue"
echo ""
echo "🔧 QUICK FIXES TO TRY:"
echo ""
echo "# Clear all caches:"
echo "docker-compose exec backend php artisan config:clear"
echo "docker-compose exec backend php artisan route:clear"
echo "docker-compose exec backend php artisan cache:clear"
echo ""
echo "# Restart everything:"
echo "docker-compose restart"
echo ""
echo "# Test scheduler manually:"
echo "docker-compose exec backend php artisan schedule:run --verbose"
echo ""
echo "# Process posts manually:"
echo "docker-compose exec backend php artisan posts:process-scheduled"