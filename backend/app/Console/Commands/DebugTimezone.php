<?php

// app/Console/Commands/DebugTimezone.php - Debug command to check timezone setup

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Carbon\Carbon;

class DebugTimezone extends Command
{
    protected $signature = 'debug:timezone';
    protected $description = 'Debug timezone configuration';

    public function handle()
    {
        $this->info('=== Timezone Debug Information ===');
        
        // Server timezone info
        $this->info('Server PHP timezone: ' . date_default_timezone_get());
        $this->info('Laravel app timezone: ' . config('app.timezone'));
        
        // Current times in different formats
        $now = Carbon::now();
        $utcNow = Carbon::now('UTC');
        
        $this->info('Current Carbon::now(): ' . $now->toDateTimeString() . ' (' . $now->timezone->getName() . ')');
        $this->info('Current Carbon::now(UTC): ' . $utcNow->toDateTimeString() . ' (UTC)');
        
        // Test timezone conversion
        $mexicoTime = Carbon::now('America/Mexico_City');
        $this->info('Mexico City time: ' . $mexicoTime->toDateTimeString() . ' (America/Mexico_City)');
        $this->info('Mexico City -> UTC: ' . $mexicoTime->utc()->toDateTimeString() . ' (UTC)');
        
        // Test user timezone conversion
        $userTimezone = 'America/New_York';
        $userTime = '2024-01-15 14:30:00';
        
        $this->info("\nTest conversion:");
        $this->info("User time: {$userTime} ({$userTimezone})");
        
        $carbonUserTime = Carbon::parse($userTime, $userTimezone);
        $this->info("Parsed in user timezone: " . $carbonUserTime->toDateTimeString() . ' (' . $carbonUserTime->timezone->getName() . ')');
        $this->info("Converted to UTC: " . $carbonUserTime->utc()->toDateTimeString() . ' (UTC)');
        
        return 0;
    }
}