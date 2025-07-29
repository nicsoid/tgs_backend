<?php
use App\Models\ScheduledPost;
use App\Models\PostLog;
use Carbon\Carbon;

echo "🌍 TIMEZONE FIX & ANALYSIS\n";
echo "==========================\n\n";

$serverUtc = Carbon::now('UTC');
echo "Current UTC: {$serverUtc->format('Y-m-d H:i:s T')}\n\n";

$posts = ScheduledPost::all();
echo "Total posts: {$posts->count()}\n\n";

$fixedTimes = 0;
$processableNow = 0;

foreach ($posts as $post) {
    echo "Post {$post->id} ({$post->status}):\n";
    
    $userTimes = $post->schedule_times ?? [];
    $utcTimes = $post->schedule_times_utc ?? [];
    $userTimezone = $post->user_timezone ?? 'UTC';
    $groupIds = $post->group_ids ?? [];
    
    echo "  Timezone: {$userTimezone}\n";
    echo "  Groups: " . count($groupIds) . "\n";
    
    $needsFix = false;
    $newUtcTimes = [];
    
    foreach ($userTimes as $index => $userTime) {
        try {
            $userCarbon = Carbon::parse($userTime, $userTimezone);
            $correctUtc = $userCarbon->utc()->format('Y-m-d H:i:s');
            $storedUtc = $utcTimes[$index] ?? null;
            
            echo "  Time {$index}: {$userTime} → {$correctUtc}\n";
            
            if (!$storedUtc || $storedUtc !== $correctUtc) {
                echo "    Fixed: {$storedUtc} → {$correctUtc}\n";
                $needsFix = true;
                $fixedTimes++;
            }
            $newUtcTimes[] = $correctUtc;
            
            // Check if processable
            $pastCutoff = $serverUtc->copy()->subHours(6);
            $futureCutoff = $serverUtc->copy()->addHours(1);
            $scheduledUtc = Carbon::parse($correctUtc, 'UTC');
            
            if ($scheduledUtc->gte($pastCutoff) && $scheduledUtc->lte($futureCutoff)) {
                $processableNow += count($groupIds);
                echo "    ✅ PROCESSABLE NOW\n";
            } else {
                $diff = $serverUtc->diffInMinutes($scheduledUtc, false);
                echo "    ⏰ " . ($diff > 0 ? $diff . ' min ago' : abs($diff) . ' min future') . "\n";
            }
            
        } catch (Exception $e) {
            echo "    ❌ Error: {$e->getMessage()}\n";
            $newUtcTimes[] = $storedUtc ?? $userTime;
        }
    }
    
    if ($needsFix) {
        $post->schedule_times_utc = $newUtcTimes;
        $post->save();
        echo "  ✅ SAVED FIXES\n";
    }
    
    echo "\n";
}

echo "🎯 SUMMARY:\n";
echo "Times fixed: {$fixedTimes}\n";
echo "Messages processable now: {$processableNow}\n\n";

if ($processableNow > 0) {
    echo "✅ READY TO SEND!\n";
    echo "Run: php artisan posts:process-scheduled --force\n";
} else {
    echo "⏰ No messages in current window\n";
    echo "Use: php artisan messages:force-send\n";
}
