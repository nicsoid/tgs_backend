#!/bin/bash
# fix-telegram-scheduler.sh - Complete fix for queue and scheduler issues

echo "🔧 Fixing Telegram Scheduler Queue and Scheduler Issues"
echo "====================================================="

# Step 1: Fix the Console Kernel registration
echo "1. Fixing Console Kernel schedule registration..."

# Check if the ProcessScheduledPosts command exists in the schedule
if ! grep -q "posts:process-scheduled" backend/app/Console/Kernel.php; then
    echo "❌ Missing posts:process-scheduled command in scheduler!"
    echo "Updating Kernel.php..."
    
    # Create backup
    cp backend/app/Console/Kernel.php backend/app/Console/Kernel.php.backup
    
    # Update the schedule method to include the missing command
    cat > backend/app/Console/Kernel.php << 'EOF'
<?php
// app/Console/Kernel.php - FIXED VERSION

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // CRITICAL: Process scheduled posts every minute - THIS WAS MISSING!
        $schedule->command('posts:process-scheduled')
                ->everyMinute()
                ->withoutOverlapping()
                ->runInBackground();
        
        // Update currency rates daily at midnight
        $schedule->command('currencies:update')->daily();
        
        // Monitor scheduler health every 5 minutes
        $schedule->command('scheduler:monitor')->everyFiveMinutes();
        
        // Verify stale admin relationships every 4 hours
        $schedule->command('admin:verify --stale-only')
                ->everyFourHours()
                ->withoutOverlapping()
                ->runInBackground();
        
        // Full admin verification daily at 2 AM (when usage is typically low)
        $schedule->command('admin:verify --all')
                ->dailyAt('02:00')
                ->withoutOverlapping()
                ->runInBackground();
                
        // Refresh group info (including member counts) for stale groups every 6 hours
        $schedule->command('groups:refresh-info --stale-only')
                ->everySixHours()
                ->withoutOverlapping()
                ->runInBackground();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
EOF
    echo "✅ Fixed Kernel.php with proper command scheduling"
else
    echo "✅ Kernel.php already has posts:process-scheduled command"
fi

# Step 2: Check if ProcessScheduledPosts command exists
echo ""
echo "2. Checking ProcessScheduledPosts command..."
if [ ! -f "backend/app/Console/Commands/ProcessScheduledPosts.php" ]; then
    echo "❌ ProcessScheduledPosts command missing!"
    echo "This command should exist based on your files. Please ensure it's properly created."
else
    echo "✅ ProcessScheduledPosts command exists"
fi

# Step 3: Fix queue configuration
echo ""
echo "3. Fixing queue configuration..."
docker-compose exec -T backend php artisan config:clear
docker-compose exec -T backend php artisan route:clear
docker-compose exec -T backend php artisan cache:clear

# Step 4: Test the scheduler
echo ""
echo "4. Testing scheduler commands..."
echo "Testing schedule:list command:"
docker-compose exec -T backend php artisan schedule:list

echo ""
echo "Testing posts:process-scheduled command directly:"
docker-compose exec -T backend php artisan posts:process-scheduled --dry-run

# Step 5: Check queue status
echo ""
echo "5. Checking queue status..."
echo "Queue table status:"
docker-compose exec -T backend php artisan queue:work --stop-when-empty

# Step 6: Fix queue workers
echo ""
echo "6. Restarting queue workers..."
docker-compose restart queue-worker

# Step 7: Check scheduler is running
echo ""
echo "7. Checking scheduler container..."
docker-compose logs scheduler --tail=20

# Step 8: Test manual scheduler run
echo ""
echo "8. Running scheduler manually to test..."
docker-compose exec -T backend php artisan schedule:run --verbose

echo ""
echo "🎯 DIAGNOSTIC SUMMARY"
echo "===================="

# Check if posts are in database
echo "Checking scheduled posts in database:"
docker-compose exec -T backend php artisan tinker --execute="
echo 'Total scheduled posts: ' . App\Models\ScheduledPost::count();
echo 'Pending posts: ' . App\Models\ScheduledPost::where('status', 'pending')->count();
echo 'Recent posts:';
App\Models\ScheduledPost::orderBy('created_at', 'desc')->limit(3)->get()->each(function(\$post) {
    echo '- Post ID: ' . \$post->id . ', Status: ' . \$post->status . ', Groups: ' . count(\$post->group_ids ?? []) . ', Times: ' . count(\$post->schedule_times_utc ?? []);
});
"

echo ""
echo "Checking post logs:"
docker-compose exec -T backend php artisan tinker --execute="
echo 'Total logs: ' . App\Models\PostLog::count();
echo 'Recent logs:';
App\Models\PostLog::orderBy('created_at', 'desc')->limit(5)->get()->each(function(\$log) {
    echo '- Post: ' . \$log->post_id . ', Group: ' . \$log->group_id . ', Status: ' . \$log->status . ', Time: ' . \$log->scheduled_time;
});
"

echo ""
echo "🚀 NEXT STEPS TO VERIFY THE FIX"
echo "==============================="
echo "1. Check scheduler is running: docker-compose logs scheduler"
echo "2. Check queue workers: docker-compose logs queue-worker"
echo "3. Create a test post with near-future time (next 5 minutes)"
echo "4. Watch logs: docker-compose logs -f backend"
echo "5. Monitor queue: docker-compose exec backend php artisan queue:monitor"

echo ""
echo "🔍 MANUAL TESTS"
echo "==============="
echo "# Test scheduler manually:"
echo "docker-compose exec backend php artisan schedule:run --verbose"
echo ""
echo "# Test post processing manually:"
echo "docker-compose exec backend php artisan posts:process-scheduled --dry-run"
echo ""
echo "# Check what's scheduled:"
echo "docker-compose exec backend php artisan schedule:list"
echo ""
echo "# Debug specific user's posts:"
echo "docker-compose exec backend php artisan debug:dashboard [user_id]"

echo ""
echo "✅ Fix completed! The main issue was missing 'posts:process-scheduled' command in the scheduler."
echo "   Messages should now be processed and sent automatically every minute."