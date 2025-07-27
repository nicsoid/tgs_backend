#!/bin/bash
# debug-scheduler.sh - Debug and test scheduler + queue system

echo "🔍 Debugging Telegram Scheduler System"
echo "======================================"

echo "1. CHECKING SCHEDULER REGISTRATION"
echo "==================================="
echo "Listing all scheduled commands:"
docker-compose exec backend php artisan schedule:list

echo ""
echo "2. TESTING COMMAND EXISTENCE"
echo "============================"
echo "Available commands (looking for posts:process-scheduled):"
docker-compose exec backend php artisan list | grep -E "(posts:|schedule|queue)"

echo ""
echo "3. TESTING COMMAND DIRECTLY"
echo "==========================="
echo "Running posts:process-scheduled in dry-run mode:"
docker-compose exec backend php artisan posts:process-scheduled --dry-run

echo ""
echo "4. CHECKING DATABASE STATE"
echo "=========================="
echo "Database connection test:"
docker-compose exec backend php artisan tinker --execute="
try {
    \$connected = DB::connection('mongodb')->listCollections();
    echo 'MongoDB: ✅ Connected';
} catch (Exception \$e) {
    echo 'MongoDB: ❌ Failed - ' . \$e->getMessage();
}
"

echo ""
echo "Scheduled posts status:"
docker-compose exec backend php artisan tinker --execute="
echo 'Total posts: ' . App\Models\ScheduledPost::count();
echo 'Pending posts: ' . App\Models\ScheduledPost::where('status', 'pending')->count();
echo 'Posts with future times:';
\$posts = App\Models\ScheduledPost::where('status', 'pending')->get();
foreach (\$posts as \$post) {
    \$futureCount = 0;
    if (!empty(\$post->schedule_times_utc)) {
        foreach (\$post->schedule_times_utc as \$time) {
            if (Carbon\Carbon::parse(\$time, 'UTC')->isFuture()) {
                \$futureCount++;
            }
        }
    }
    echo '- Post ' . \$post->id . ': ' . \$futureCount . ' future times, ' . count(\$post->group_ids ?? []) . ' groups';
}
"

echo ""
echo "5. CHECKING QUEUE SYSTEM"
echo "========================"
echo "Queue connection test:"
docker-compose exec backend php artisan tinker --execute="
try {
    \$redis = Redis::connection();
    \$redis->ping();
    echo 'Redis: ✅ Connected';
} catch (Exception \$e) {
    echo 'Redis: ❌ Failed - ' . \$e->getMessage();
}
"

echo ""
echo "Checking queue tables/storage:"
docker-compose exec backend php artisan queue:monitor

echo ""
echo "6. RUNNING TESTS"
echo "================"
echo "Manual scheduler run (verbose):"
docker-compose exec backend php artisan schedule:run --verbose

echo ""
echo "7. REAL-TIME MONITORING"
echo "======================="
echo "Queue worker status:"
docker-compose ps queue-worker

echo ""
echo "Queue worker logs (last 10 lines):"
docker-compose logs queue-worker --tail=10

echo ""
echo "Scheduler logs (last 10 lines):"
docker-compose logs scheduler --tail=10

echo ""
echo "8. CREATE TEST POST"
echo "==================="
echo "To test the system, create a post with a time 2-3 minutes in the future."
echo "Then monitor with these commands:"
echo ""
echo "# Watch scheduler in real-time:"
echo "docker-compose logs -f scheduler"
echo ""
echo "# Watch queue workers:"
echo "docker-compose logs -f queue-worker"
echo ""
echo "# Watch backend logs:"
echo "docker-compose logs -f backend"
echo ""
echo "# Check job processing:"
echo "docker-compose exec backend php artisan queue:work --stop-when-empty"

echo ""
echo "9. MANUAL TESTING COMMANDS"
echo "=========================="
echo "# Test scheduler manually:"
echo "docker-compose exec backend php artisan schedule:run --verbose"
echo ""
echo "# Test post processing manually:"
echo "docker-compose exec backend php artisan posts:process-scheduled"
echo ""
echo "# Check what jobs are in queue:"
echo "docker-compose exec backend redis-cli -h redis LLEN queues:default"
echo ""
echo "# Process one job manually:"
echo "docker-compose exec backend php artisan queue:work --once"

echo ""
echo "🎯 EXPECTED BEHAVIOR"
echo "==================="
echo "1. schedule:list should show 'posts:process-scheduled' running every minute"
echo "2. Scheduler should run the command every minute"
echo "3. Command should find posts with future times and dispatch jobs"
echo "4. Queue workers should process jobs and send messages via Telegram"
echo "5. PostLog entries should be created with 'sent' or 'failed' status"

echo ""
echo "✅ Debug complete! If scheduler shows 'No scheduled commands', the Kernel.php fix is needed."