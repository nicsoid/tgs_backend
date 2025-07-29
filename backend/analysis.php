docker-compose exec backend php artisan tinker --execute="
use App\Models\ScheduledPost;
use App\Models\PostLog;
use Carbon\Carbon;

echo '🔍 QUICK SCHEDULE DIAGNOSIS' . PHP_EOL;
echo '============================' . PHP_EOL;

\$now = Carbon::now('UTC');
echo 'Current UTC: ' . \$now->format('Y-m-d H:i:s T') . PHP_EOL;
echo 'Current Local: ' . Carbon::now()->format('Y-m-d H:i:s T') . PHP_EOL . PHP_EOL;

\$posts = ScheduledPost::get();
echo 'Pending posts: ' . \$posts->count() . PHP_EOL . PHP_EOL;

\$processableNow = 0;
\$futureCount = 0;
\$overdueCount = 0;

foreach (\$posts as \$post) {
    echo 'POST ' . \$post->id . ':' . PHP_EOL;
    echo '  User TZ: ' . (\$post->user_timezone ?? 'None') . PHP_EOL;
    echo '  Groups: ' . count(\$post->group_ids ?? []) . PHP_EOL;
    echo '  User times: ' . count(\$post->schedule_times ?? []) . PHP_EOL;
    echo '  UTC times: ' . count(\$post->schedule_times_utc ?? []) . PHP_EOL;
    
    foreach (\$post->schedule_times_utc ?? [] as \$i => \$utcTime) {
        \$userTime = \$post->schedule_times[\$i] ?? 'N/A';
        \$scheduledUtc = Carbon::parse(\$utcTime, 'UTC');
        \$diffMinutes = \$now->diffInMinutes(\$scheduledUtc, false);
        
        echo '  Time ' . \$i . ': ' . \$userTime . ' → ' . \$utcTime . PHP_EOL;
        
        if (\$scheduledUtc->isFuture()) {
            echo '    ⏰ FUTURE: ' . \$diffMinutes . ' minutes from now' . PHP_EOL;
            \$futureCount++;
        } else {
            echo '    🕐 PAST: ' . \$diffMinutes . ' minutes ago' . PHP_EOL;
            if (\$diffMinutes > 60) {
                echo '    ⚠️  OVERDUE!' . PHP_EOL;
                \$overdueCount++;
            }
        }
        
        // Check processability (ProcessScheduledPosts logic)
        \$pastCutoff = \$now->copy()->subHours(1);
        \$futureCutoff = \$now->copy()->addMinutes(2);
        \$isProcessable = \$scheduledUtc->gte(\$pastCutoff) && \$scheduledUtc->lte(\$futureCutoff);
        
        echo '    📋 PROCESSABLE: ' . (\$isProcessable ? 'YES ✅' : 'NO ❌') . PHP_EOL;
        if (\$isProcessable) \$processableNow++;
        
        // Check if sent
        \$sent = PostLog::where('post_id', \$post->id)
            ->where('scheduled_time', \$userTime)
            ->where('status', 'sent')
            ->exists();
        echo '    📤 SENT: ' . (\$sent ? 'YES' : 'NO') . PHP_EOL;
    }
    echo PHP_EOL;
}

echo '🎯 SUMMARY:' . PHP_EOL;
echo '===========' . PHP_EOL;
echo 'Processable now: ' . \$processableNow . PHP_EOL;
echo 'Future times: ' . \$futureCount . PHP_EOL;
echo 'Overdue times: ' . \$overdueCount . PHP_EOL . PHP_EOL;

if (\$processableNow === 0) {
    echo '❌ NO MESSAGES READY TO SEND!' . PHP_EOL;
    if (\$futureCount > 0) {
        echo '💡 All times are in the future. Wait or use --force' . PHP_EOL;
    }
    if (\$overdueCount > 0) {
        echo '💡 Some times are overdue. Use force-send command' . PHP_EOL;
    }
} else {
    echo '✅ ' . \$processableNow . ' messages ready to send!' . PHP_EOL;
    echo '💡 Run: php artisan posts:process-scheduled' . PHP_EOL;
}

echo PHP_EOL . '🔧 NEXT STEPS:' . PHP_EOL;
echo '1. Check scheduler: php artisan schedule:list' . PHP_EOL;
echo '2. Test processing: php artisan posts:process-scheduled --dry-run' . PHP_EOL;
echo '3. Force send: php artisan messages:force-send --dry-run' . PHP_EOL;
"