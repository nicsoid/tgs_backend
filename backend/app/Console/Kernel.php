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
        $schedule->command('posts:process-scheduled');
                //->everyMinute()
                //->withoutOverlapping()
                //->runInBackground();
        
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
