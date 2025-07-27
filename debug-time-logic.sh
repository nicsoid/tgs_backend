#!/bin/bash
# debug-time-logic.sh - Debug why posts aren't being selected for sending

echo "🕐 DEBUGGING TIME SELECTION LOGIC"
echo "================================="

echo "The command runs but dispatches 0 jobs. This means the time logic is wrong."
echo "Let's debug exactly what's happening with time selection."
echo ""

echo "1. CHECK CURRENT TIME AND POSTS IN DATABASE"
echo "==========================================="
docker-compose exec backend php artisan tinker --execute="
use App\Models\ScheduledPost;
use Carbon\Carbon;

echo 'Current UTC time: ' . Carbon::now('UTC')->format('Y-m-d H:i:s');
echo 'Current system timezone: ' . date_default_timezone_get();

\$posts = ScheduledPost::where('status', 'pending')->get();
echo 'Total pending posts: ' . \$posts->count();

foreach (\$posts as \$post) {
    echo '=== POST: ' . \$post->id . ' ===';
    echo 'Status: ' . \$post->status;
    echo 'User timezone: ' . \$post->user_timezone;
    echo 'Groups: ' . count(\$post->group_ids ?? []);
    
    echo 'Original schedule times (user timezone):';
    foreach (\$post->schedule_times ?? [] as \$index => \$time) {
        echo '  [' . \$index . '] ' . \$time;
    }
    
    echo 'UTC schedule times:';
    foreach (\$post->schedule_times_utc ?? [] as \$index => \$time) {
        echo '  [' . \$index . '] ' . \$time;
    }
    
    // Check if any times should be processed NOW
    \$now = Carbon::now('UTC');
    \$cutoffTime = \$now->copy()->addMinutes(2);
    
    echo 'Times that should be processed (between past 1 hour and next 2 minutes):';
    foreach (\$post->schedule_times_utc ?? [] as \$index => \$timeUtc) {
        \$scheduledUtc = Carbon::parse(\$timeUtc, 'UTC');
        \$isPastDue = \$scheduledUtc->lte(\$cutoffTime);
        \$notTooOld = \$scheduledUtc->gte(\$now->copy()->subHours(1));
        \$shouldProcess = \$isPastDue && \$notTooOld;
        
        echo '  [' . \$index . '] ' . \$timeUtc . ' -> Should process: ' . (\$shouldProcess ? 'YES' : 'NO');
        echo '    - Is past due: ' . (\$isPastDue ? 'YES' : 'NO');
        echo '    - Not too old: ' . (\$notTooOld ? 'YES' : 'NO');
        echo '    - Minutes ago: ' . \$now->diffInMinutes(\$scheduledUtc, false);
    }
    echo '';
}
"

echo ""
echo "2. CHECK POST LOG DUPLICATES"
echo "============================"
echo "Checking if messages were already sent (duplicate prevention):"
docker-compose exec backend php artisan tinker --execute="
use App\Models\PostLog;
use App\Models\ScheduledPost;

\$posts = ScheduledPost::where('status', 'pending')->get();

foreach (\$posts as \$post) {
    echo '=== CHECKING DUPLICATES FOR POST: ' . \$post->id . ' ===';
    
    foreach (\$post->schedule_times ?? [] as \$index => \$originalTime) {
        \$utcTime = \$post->schedule_times_utc[\$index] ?? null;
        
        if (\$utcTime) {
            foreach (\$post->group_ids ?? [] as \$groupId) {
                \$alreadySent = PostLog::where('post_id', \$post->id)
                    ->where('group_id', \$groupId)
                    ->where('scheduled_time', \$originalTime)
                    ->where('status', 'sent')
                    ->exists();
                
                echo 'Time: ' . \$originalTime . ' -> Group: ' . \$groupId . ' -> Already sent: ' . (\$alreadySent ? 'YES' : 'NO');
            }
        }
    }
    echo '';
}
"

echo ""
echo "3. MANUALLY TEST THE PROCESSING LOGIC"
echo "====================================="
echo "Simulating the exact logic from ProcessScheduledPosts command:"
docker-compose exec backend php artisan tinker --execute="
use App\Models\ScheduledPost;
use Carbon\Carbon;

\$now = Carbon::now('UTC');
\$cutoffTime = \$now->copy()->addMinutes(2);

echo 'Processing window: ' . \$now->format('Y-m-d H:i:s') . ' to ' . \$cutoffTime->format('Y-m-d H:i:s');

\$totalDispatched = 0;

ScheduledPost::select('_id', 'group_ids', 'schedule_times', 'schedule_times_utc', 'content', 'user_timezone')
    ->chunk(100, function (\$postChunk) use (\$now, \$cutoffTime, &\$totalDispatched) {
        foreach (\$postChunk as \$post) {
            \$dispatched = 0;
            \$groupIds = \$post->group_ids ?? [];
            \$scheduleTimesUtc = \$post->schedule_times_utc ?? [];
            \$scheduleTimesUser = \$post->schedule_times ?? [];

            if (empty(\$groupIds) || empty(\$scheduleTimesUtc)) {
                echo 'Skipping post ' . \$post->_id . ' - no groups or times';
                continue;
            }

            foreach (\$scheduleTimesUtc as \$index => \$scheduledTimeUtc) {
                try {
                    \$scheduledUtc = Carbon::parse(\$scheduledTimeUtc, 'UTC');
                    
                    // Check if this time has passed (with buffer) and is not too far in the past
                    if (\$scheduledUtc->lte(\$cutoffTime) && \$scheduledUtc->gte(\$now->copy()->subHours(1))) {
                        \$originalScheduleTime = \$scheduleTimesUser[\$index] ?? \$scheduledTimeUtc;
                        
                        echo 'Found processable time: ' . \$scheduledTimeUtc . ' (original: ' . \$originalScheduleTime . ')';
                        
                        foreach (\$groupIds as \$groupId) {
                            // Check if already processed
                            \$alreadyProcessed = \DB::connection('mongodb')
                                ->table('post_logs')
                                ->where('post_id', \$post->_id)
                                ->where('group_id', \$groupId)
                                ->where('scheduled_time', \$originalScheduleTime)
                                ->where('status', 'sent')
                                ->exists();
                            
                            if (!\$alreadyProcessed) {
                                echo '  -> Would dispatch job for group: ' . \$groupId;
                                \$dispatched++;
                            } else {
                                echo '  -> Already sent to group: ' . \$groupId;
                            }
                        }
                    } else {
                        echo 'Time ' . \$scheduledTimeUtc . ' not in processing window';
                        echo '  - Scheduled: ' . \$scheduledUtc->format('Y-m-d H:i:s');
                        echo '  - Now: ' . \$now->format('Y-m-d H:i:s');
                        echo '  - Cutoff: ' . \$cutoffTime->format('Y-m-d H:i:s');
                        echo '  - Is past cutoff: ' . (\$scheduledUtc->lte(\$cutoffTime) ? 'YES' : 'NO');
                        echo '  - Is within 1 hour: ' . (\$scheduledUtc->gte(\$now->copy()->subHours(1)) ? 'YES' : 'NO');
                    }
                } catch (Exception \$e) {
                    echo 'Error processing time ' . \$scheduledTimeUtc . ': ' . \$e->getMessage();
                }
            }
            
            if (\$dispatched > 0) {
                echo 'Total jobs would be dispatched for post ' . \$post->_id . ': ' . \$dispatched;
                \$totalDispatched += \$dispatched;
            }
        }
        
        return true;
    });

echo 'TOTAL JOBS THAT SHOULD BE DISPATCHED: ' . \$totalDispatched;
"

echo ""
echo "4. CHECK TIMEZONE CONVERSION ISSUES"
echo "=================================="
echo "Testing timezone conversion specifically:"
docker-compose exec backend php artisan tinker --execute="
use Carbon\Carbon;

echo 'Testing timezone conversion issues:';

// Test the Mexico City timezone conversion
\$userTime = '2025-07-27T11:35';
\$userTimezone = 'America/Mexico_City';

echo 'Original time: ' . \$userTime . ' (' . \$userTimezone . ')';

try {
    \$carbonUserTime = Carbon::parse(\$userTime, \$userTimezone);
    echo 'Parsed in user timezone: ' . \$carbonUserTime->format('Y-m-d H:i:s T');
    echo 'Converted to UTC: ' . \$carbonUserTime->utc()->format('Y-m-d H:i:s T');
    
    // Check if this UTC time is in the past
    \$now = Carbon::now('UTC');
    echo 'Current UTC: ' . \$now->format('Y-m-d H:i:s T');
    echo 'Is past due: ' . (\$carbonUserTime->utc()->lte(\$now) ? 'YES' : 'NO');
    echo 'Minutes difference: ' . \$now->diffInMinutes(\$carbonUserTime->utc(), false);
    
} catch (Exception \$e) {
    echo 'Timezone conversion error: ' . \$e->getMessage();
}
"

echo ""
echo "🎯 DIAGNOSIS SUMMARY"
echo "==================="
echo "This debug will show exactly why posts aren't being dispatched:"
echo "1. If times are not in the processing window"
echo "2. If posts were already sent (duplicates)"
echo "3. If timezone conversion is wrong"
echo "4. If the scheduling logic has bugs"
echo ""
echo "Based on the output above, we can fix the exact issue!"