<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class FixQueue extends Command
{
    protected $signature = 'queue:fix';
    protected $description = 'Fix queue configuration for MongoDB setup';

    public function handle()
    {
        $this->info('🔧 Fixing Queue Configuration');
        $this->info('=============================');

        // Step 1: Create SQLite database for queue
        $queueDbPath = database_path('queue.sqlite');
        
        if (!File::exists($queueDbPath)) {
            File::put($queueDbPath, '');
            $this->info('✅ Created queue.sqlite database');
        } else {
            $this->info('✅ queue.sqlite already exists');
        }

        // Step 2: Update .env file
        $envPath = base_path('.env');
        $envContent = File::get($envPath);

        // Add queue configuration if not present
        if (!str_contains($envContent, 'DB_QUEUE_CONNECTION')) {
            $queueConfig = "\n# Queue Configuration (SQLite for compatibility)\n";
            $queueConfig .= "DB_QUEUE_CONNECTION=sqlite\n";
            $queueConfig .= "DB_QUEUE_DATABASE=" . $queueDbPath . "\n";
            
            File::append($envPath, $queueConfig);
            $this->info('✅ Added queue configuration to .env');
        } else {
            $this->info('✅ Queue configuration already in .env');
        }

        // Step 3: Create queue tables
        try {
            $this->call('queue:table', ['--database' => 'sqlite']);
            $this->call('migrate', ['--database' => 'sqlite']);
            $this->info('✅ Created queue tables');
        } catch (\Exception $e) {
            $this->warn('Queue tables may already exist: ' . $e->getMessage());
        }

        // Step 4: Clear existing problematic jobs
        try {
            \DB::connection('mongodb')->table('jobs')->delete();
            $this->info('✅ Cleared MongoDB jobs table');
        } catch (\Exception $e) {
            $this->warn('Could not clear MongoDB jobs: ' . $e->getMessage());
        }

        $this->info("\n🚀 Queue fix completed!");
        $this->info("Now restart your queue worker:");
        $this->line("php artisan queue:work --verbose");

        return 0;
    }
}