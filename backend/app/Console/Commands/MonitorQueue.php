<?php
// app/Console/Commands/MonitorQueue.php - Queue Monitoring
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class MonitorQueue extends Command
{
    protected $signature = 'queue:monitor';
    protected $description = 'Monitor queue performance and health';

    public function handle()
    {
        $this->info('Queue Performance Monitor');
        $this->info('========================');

        // Check queue sizes
        $this->checkQueueSizes();
        
        // Check failed jobs
        $this->checkFailedJobs();
        
        // Check processing rates
        $this->checkProcessingRates();
        
        // Check stuck jobs
        $this->checkStuckJobs();
    }

    private function checkQueueSizes()
    {
        $this->info("\n📊 Queue Sizes:");
        
        $queues = ['default', 'telegram-messages-1', 'telegram-messages-2', 'telegram-messages-3'];
        
        foreach ($queues as $queue) {
            try {
                $size = Redis::llen("queues:{$queue}");
                $this->line("  {$queue}: {$size} jobs");
            } catch (\Exception $e) {
                $this->line("  {$queue}: Unable to check");
            }
        }
    }

    private function checkFailedJobs()
    {
        $failedJobs = DB::table('failed_jobs')->count();
        $recentFailed = DB::table('failed_jobs')
            ->where('failed_at', '>=', now()->subHour())
            ->count();

        $this->info("\n❌ Failed Jobs:");
        $this->line("  Total: {$failedJobs}");
        $this->line("  Last hour: {$recentFailed}");
    }

    private function checkProcessingRates()
    {
        $this->info("\n⚡ Processing Rates (last hour):");
        
        $sentLastHour = DB::connection('mongodb')
            ->table('post_logs')
            ->where('status', 'sent')
            ->where('sent_at', '>=', now()->subHour())
            ->count();

        $failedLastHour = DB::connection('mongodb')
            ->table('post_logs')
            ->where('status', 'failed')
            ->where('sent_at', '>=', now()->subHour())
            ->count();

        $total = $sentLastHour + $failedLastHour;
        $successRate = $total > 0 ? round(($sentLastHour / $total) * 100, 2) : 0;

        $this->line("  Messages sent: {$sentLastHour}");
        $this->line("  Messages failed: {$failedLastHour}");
        $this->line("  Success rate: {$successRate}%");
    }

    private function checkStuckJobs()
    {
        $stuckJobs = DB::table('jobs')
            ->where('reserved_at', '<', now()->subMinutes(10))
            ->where('reserved_at', '>', 0)
            ->count();

        if ($stuckJobs > 0) {
            $this->warn("\n⚠️  Warning: {$stuckJobs} jobs appear to be stuck");
        } else {
            $this->info("\n✅ No stuck jobs detected");
        }
    }
}