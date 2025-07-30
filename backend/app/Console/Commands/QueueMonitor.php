<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\DB;
use App\Models\PostLog;
use Carbon\Carbon;

class QueueMonitor extends Command
{
    protected $signature = 'queue:monitor';
    protected $description = 'Monitor queue health and performance';

    public function handle()
    {
        $this->info('📊 Queue Health Monitor - ' . now()->format('Y-m-d H:i:s'));
        $this->info('=================================================');

        // Queue sizes
        $this->checkQueueSizes();
        
        // Processing rates
        $this->checkProcessingRates();
        
        // Failed jobs
        $this->checkFailedJobs();
        
        // System health
        $this->checkSystemHealth();
    }

    private function checkQueueSizes()
    {
        $this->info("\n📦 Queue Sizes:");
        
        $redis = Redis::connection();
        $queues = ['telegram-high', 'telegram-medium-1', 'telegram-medium-2', 'telegram-low'];
        $totalQueued = 0;
        
        foreach ($queues as $queue) {
            $size = $redis->llen("queues:{$queue}");
            $totalQueued += $size;
            
            $status = $size > 100 ? '⚠️ ' : ($size > 0 ? '📤 ' : '✅ ');
            $this->line("  {$status}{$queue}: {$size} jobs");
        }
        
        $this->line("  📊 Total queued: {$totalQueued}");
        
        if ($totalQueued > 1000) {
            $this->warn('⚠️  High queue backlog! Consider scaling workers.');
        }
    }

    private function checkProcessingRates()
    {
        $this->info("\n⚡ Processing Rates (Last Hour):");
        
        $lastHour = Carbon::now()->subHour();
        
        $sent = PostLog::where('status', 'sent')
            ->where('created_at', '>=', $lastHour)
            ->count();
            
        $failed = PostLog::where('status', 'failed')
            ->where('created_at', '>=', $lastHour)
            ->count();
            
        $total = $sent + $failed;
        $successRate = $total > 0 ? round(($sent / $total) * 100, 1) : 0;
        
        $this->line("  📤 Messages sent: {$sent}");
        $this->line("  ❌ Messages failed: {$failed}");
        $this->line("  📊 Success rate: {$successRate}%");
        $this->line("  🏃 Rate: " . round($sent / 60, 1) . " messages/minute");
        
        if ($successRate < 95) {
            $this->warn("⚠️  Low success rate: {$successRate}%");
        }
    }

    private function checkFailedJobs()
    {
        $this->info("\n💥 Failed Jobs:");
        
        $totalFailed = DB::table('failed_jobs')->count();
        $recentFailed = DB::table('failed_jobs')
            ->where('failed_at', '>=', Carbon::now()->subHour())
            ->count();
            
        $this->line("  💥 Total failed jobs: {$totalFailed}");
        $this->line("  🕐 Failed last hour: {$recentFailed}");
        
        if ($recentFailed > 10) {
            $this->warn("⚠️  High failure rate in last hour!");
        }
    }

    private function checkSystemHealth()
    {
        $this->info("\n🏥 System Health:");
        
        // Memory usage
        $memoryUsage = round(memory_get_usage(true) / 1024 / 1024, 2);
        $this->line("  💾 Memory usage: {$memoryUsage} MB");
        
        // Redis connectivity
        try {
            Redis::connection()->ping();
            $this->line("  ✅ Redis: Connected");
        } catch (\Exception $e) {
            $this->line("  ❌ Redis: Disconnected - " . $e->getMessage());
        }
        
        // MongoDB connectivity
        try {
            DB::connection('mongodb')->getPdo();
            $this->line("  ✅ MongoDB: Connected");
        } catch (\Exception $e) {
            $this->line("  ❌ MongoDB: Disconnected - " . $e->getMessage());
        }
    }
}
