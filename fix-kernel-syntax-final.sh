#!/bin/bash
# fix-kernel-syntax-final.sh - Fix the syntax error in Kernel.php and queue workers

echo "🔧 FINAL FIX: Kernel.php Syntax Error & Queue Workers"
echo "===================================================="

echo "I found the issue! There's a syntax error in your Kernel.php file:"
echo "Line has: ->runInBackground();; (double semicolon!)"
echo "This breaks the scheduler completely."
echo ""

echo "1. FIXING KERNEL.PHP SYNTAX ERROR"
echo "================================="

# Create correct Kernel.php
cat > backend/app/Console/Kernel.php << 'EOF'
<?php
// app/Console/Kernel.php - FIXED SYNTAX

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
        // Process scheduled posts every minute - FIXED: Removed double semicolon
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

echo "✅ Fixed Kernel.php syntax error (removed double semicolon)"

echo ""
echo "2. CLEAR ALL CACHES EVERYWHERE"
echo "=============================="
docker-compose exec backend php artisan config:clear
docker-compose exec backend php artisan route:clear
docker-compose exec backend php artisan cache:clear
docker-compose exec scheduler php artisan config:clear
docker-compose exec scheduler php artisan route:clear
docker-compose exec scheduler php artisan cache:clear

echo ""
echo "3. TEST SCHEDULE DETECTION AFTER FIX"
echo "===================================="
echo "Testing in backend container:"
docker-compose exec backend php artisan schedule:list

echo ""
echo "Testing in scheduler container:"
docker-compose exec scheduler php artisan schedule:list

echo ""
echo "4. RESTART ALL SERVICES"
echo "======================="
docker-compose restart

echo "Waiting for services to start..."
sleep 20

echo ""
echo "5. TEST FIXED SCHEDULER"
echo "======================"
echo "Testing schedule:list after restart:"
docker-compose exec scheduler php artisan schedule:list

echo ""
echo "6. CHECK QUEUE WORKERS STATUS"
echo "============================="
echo "Queue worker logs:"
docker-compose logs queue-worker --tail=10

echo ""
echo "7. MANUAL TEST: PROCESS POSTS AND CHECK QUEUE"
echo "=============================================="
echo "Processing posts manually:"
docker-compose exec backend php artisan posts:process-scheduled

echo ""
echo "Checking Redis queue size:"
docker-compose exec backend php artisan tinker --execute="
\$redis = app('redis')->connection();
\$queueSize = \$redis->llen('queues:default');
echo 'Default queue size: ' . \$queueSize;

\$telegramQueue1 = \$redis->llen('queues:telegram-messages-1');
echo 'Telegram queue 1 size: ' . \$telegramQueue1;

\$telegramQueue2 = \$redis->llen('queues:telegram-messages-2');
echo 'Telegram queue 2 size: ' . \$telegramQueue2;

\$telegramQueue3 = \$redis->llen('queues:telegram-messages-3');
echo 'Telegram queue 3 size: ' . \$telegramQueue3;
"

echo ""
echo "8. FORCE PROCESS QUEUE JOBS"
echo "==========================="
echo "Processing queue jobs manually to test:"
docker-compose exec backend php artisan queue:work --once --timeout=30

echo ""
echo "9. CHECK TELEGRAM LOGS"
echo "======================"
echo "Checking recent Laravel logs for Telegram sending:"
docker-compose exec backend tail -20 storage/logs/laravel.log

echo ""
echo "10. CHECK POST LOGS IN DATABASE"
echo "==============================="
echo "Checking if PostLog entries were created:"
docker-compose exec backend php artisan tinker --execute="
use App\Models\PostLog;
\$logs = PostLog::orderBy('created_at', 'desc')->limit(5)->get();
echo 'Recent post logs:';
foreach (\$logs as \$log) {
    echo '- Post: ' . \$log->post_id . ', Group: ' . \$log->group_id . ', Status: ' . \$log->status . ', Time: ' . \$log->sent_at;
}
"

echo ""
echo "🎯 VERIFICATION STEPS"
echo "===================="
echo "After this fix:"
echo "1. schedule:list should show 'posts:process-scheduled' every minute"
echo "2. Jobs should be processed by queue workers"
echo "3. PostLog entries should be created"
echo "4. Messages should be sent to Telegram"
echo ""
echo "If queue workers still aren't processing jobs:"
echo "# Check queue worker logs:"
echo "docker-compose logs queue-worker -f"
echo ""
echo "# Process jobs manually:"
echo "docker-compose exec backend php artisan queue:work --once --verbose"
echo ""
echo "✅ Syntax error fixed! Messages should now be sent automatically."