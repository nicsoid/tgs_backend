<?php
// Timezone Conversion Fix & Analysis
// Run with: docker-compose exec backend php artisan tinker < timezone_fix.php

use App\Models\ScheduledPost;
use App\Models\PostLog;
use App\Models\Group;
use Carbon\Carbon;

echo "🌍 TIMEZONE CONVERSION ANALYSIS & FIX\n";
echo "====================================\n\n";

// Current server information
$serverUtc = Carbon::now('UTC');
$serverLocal = Carbon::now();

echo "📅 SERVER TIME ANALYSIS\n";
echo "=======================\n";
echo "Server UTC: {$serverUtc->format('Y-m-d H:i:s T')}\n";
echo "Server Local: {$serverLocal->format('Y-m-d H:i:s T')}\n";
echo "Laravel TZ: " . config('app.timezone') . "\n";
echo "PHP TZ: " . date_default_timezone_get() . "\n\n";

// Get all posts (not just pending)
$posts = ScheduledPost::all();

if ($posts->isEmpty()) {
    echo "❌ NO POSTS FOUND!\n";
    exit;
}

echo "🔍 ANALYZING TIMEZONE CONVERSIONS\n";
echo "=================================\n\n";

foreach ($posts as $post) {
    echo "--- POST {$post->id} ---\n";
    echo "Status: {$post->status}\n";
    echo "User Timezone: " . ($post->user_timezone ?? 'NOT SET') . "\n";
    
    $userTimes = $post->schedule_times ?? [];
    $utcTimes = $post->schedule_times_utc ?? [];
    
    echo "User Times Count: " . count($userTimes) . "\n";
    echo "UTC Times Count: " . count($utcTimes) . "\n\n";
    
    if (empty($userTimes) || empty($utcTimes)) {
        echo "❌ MISSING SCHEDULE TIMES!\n\n";
        continue;
    }
    
    $userTimezone = $post->user_timezone ?? 'UTC';
    
    echo "🕐 TIME CONVERSION ANALYSIS:\n";
    echo "============================\n";
    
    foreach ($userTimes as $index => $userTime) {
        $utcTime = $utcTimes[$index] ?? null;
        
        echo "Time #{$index}:\n";
        echo "  User Input: {$userTime} ({$userTimezone})\n";
        echo "  Stored UTC: {$utcTime}\n";
        
        // Manual conversion check
        try {
            // Parse user time in their timezone
            $userCarbon = Carbon::parse($userTime, $userTimezone);
            $manualUtc = $userCarbon->utc();
            
            echo "  Manual UTC: {$manualUtc->format('Y-m-d H:i:s')}\n";
            
            // Check if stored UTC matches manual conversion
            if ($utcTime && $manualUtc->format('Y-m-d H:i:s') === $utcTime) {
                echo "  Conversion: ✅ CORRECT\n";
            } else {
                echo "  Conversion: ❌ MISMATCH!\n";
                echo "    Expected: {$manualUtc->format('Y-m-d H:i:s')}\n";
                echo "    Stored:   {$utcTime}\n";
            }
            
            // Time status analysis
            $minutesDiff = $serverUtc->diffInMinutes($manualUtc, false);
            
            if ($manualUtc->isFuture()) {
                echo "  Status: ⏰ FUTURE ({$minutesDiff} min from now)\n";
            } else {
                echo "  Status: 🕐 PAST ({$minutesDiff} min ago)\n";
            }
            
            // ProcessScheduledPosts logic check
            $pastCutoff = $serverUtc->copy()->subHours(1);
            $futureCutoff = $serverUtc->copy()->addMinutes(2);
            
            $isProcessable = $manualUtc->gte($pastCutoff) && $manualUtc->lte($futureCutoff);
            
            echo "  Processable: " . ($isProcessable ? "✅ YES" : "❌ NO") . "\n";
            echo "    Window: {$pastCutoff->format('H:i')} to {$futureCutoff->format('H:i')}\n";
            echo "    Message: {$manualUtc->format('H:i')}\n";
            
            // Show user's current time for context
            $userNow = Carbon::now($userTimezone);
            echo "  User's current time: {$userNow->format('Y-m-d H:i:s T')}\n";
            
        } catch (Exception $e) {
            echo "  ❌ ERROR: {$e->getMessage()}\n";
        }
        
        echo "\n";
    }
    
    echo str_repeat("=", 50) . "\n\n";
}

echo "🔧 FIXING TIMEZONE CONVERSIONS\n";
echo "==============================\n";

$fixedPosts = 0;
$correctedTimes = 0;

foreach ($posts as $post) {
    $userTimes = $post->schedule_times ?? [];
    $utcTimes = $post->schedule_times_utc ?? [];
    $userTimezone = $post->user_timezone ?? 'UTC';
    
    if (empty($userTimes)) {
        continue;
    }
    
    $needsFix = false;
    $newUtcTimes = [];
    
    foreach ($userTimes as $index => $userTime) {
        try {
            // Correct conversion
            $userCarbon = Carbon::parse($userTime, $userTimezone);
            $correctUtc = $userCarbon->utc()->format('Y-m-d H:i:s');
            
            $storedUtc = $utcTimes[$index] ?? null;
            
            if (!$storedUtc || $storedUtc !== $correctUtc) {
                $needsFix = true;
                echo "Post {$post->id} Time #{$index}: {$storedUtc} → {$correctUtc}\n";
                $correctedTimes++;
            }
            
            $newUtcTimes[] = $correctUtc;
            
        } catch (Exception $e) {
            echo "❌ Error fixing time for post {$post->id}: {$e->getMessage()}\n";
            $newUtcTimes[] = $storedUtc; // Keep original if error
        }
    }
    
    if ($needsFix) {
        $post->schedule_times_utc = $newUtcTimes;
        $post->save();
        $fixedPosts++;
        echo "✅ Fixed post {$post->id}\n";
    }
}

echo "\n✅ TIMEZONE FIX COMPLETE\n";
echo "========================\n";
echo "Posts fixed: {$fixedPosts}\n";
echo "Times corrected: {$correctedTimes}\n\n";

echo "🎯 FINAL ANALYSIS AFTER FIX\n";
echo "===========================\n";

$processableNow = 0;
$futureCount = 0;
$pastCount = 0;

// Re-analyze after fix
foreach ($posts as $post) {
    $post->refresh(); // Reload from database
    
    $utcTimes = $post->schedule_times_utc ?? [];
    $groupIds = $post->group_ids ?? [];
    
    foreach ($utcTimes as $utcTime) {
        try {
            $scheduledUtc = Carbon::parse($utcTime, 'UTC');
            
            if ($scheduledUtc->isFuture()) {
                $futureCount++;
            } else {
                $pastCount++;
            }
            
            // Check processability with current logic
            $pastCutoff = $serverUtc->copy()->subHours(1);
            $futureCutoff = $serverUtc->copy()->addMinutes(2);
            
            if ($scheduledUtc->gte($pastCutoff) && $scheduledUtc->lte($futureCutoff)) {
                $processableNow += count($groupIds); // Each group gets a message
            }
            
        } catch (Exception $e) {
            echo "❌ Error analyzing fixed time: {$e->getMessage()}\n";
        }
    }
}

echo "Messages processable now: {$processableNow}\n";
echo "Future messages: {$futureCount}\n";
echo "Past messages: {$pastCount}\n\n";

if ($processableNow > 0) {
    echo "✅ READY TO SEND MESSAGES!\n";
    echo "Run: php artisan posts:process-scheduled\n";
} else {
    echo "⏰ NO MESSAGES IN PROCESSING WINDOW\n";
    echo "Current window: " . $serverUtc->copy()->subHours(1)->format('H:i') . 
         " to " . $serverUtc->copy()->addMinutes(2)->format('H:i') . "\n";
    echo "\nOptions:\n";
    echo "1. Wait for next processing window\n";
    echo "2. Use --force to expand window\n";
    echo "3. Use force-send command\n";
}

echo "\n🧪 TEST COMMANDS\n";
echo "===============\n";
echo "1. Test processing: php artisan posts:process-scheduled --dry-run --debug\n";
echo "2. Force send: php artisan messages:force-send --dry-run\n";
echo "3. Check scheduler: php artisan schedule:run --verbose\n\n";

echo "🎯 DIAGNOSIS SUMMARY\n";
echo "===================\n";

if ($correctedTimes > 0) {
    echo "✅ Fixed {$correctedTimes} timezone conversion errors\n";
}

if ($processableNow > 0) {
    echo "✅ {$processableNow} messages ready to send\n";
    echo "💡 Issue was timezone conversion - now fixed!\n";
} elseif ($futureCount > 0) {
    echo "⏰ All messages scheduled for future times\n";
    echo "💡 Wait for scheduled time or use --force option\n";
} elseif ($pastCount > 0) {
    echo "🕐 All messages are in the past\n";
    echo "💡 Use force-send command for old messages\n";
} else {
    echo "❓ No scheduled messages found\n";
}

echo "\n";