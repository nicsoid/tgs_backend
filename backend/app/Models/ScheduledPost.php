<?php
// app/Models/ScheduledPost.php - Always Editable Version

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;
use Carbon\Carbon;

class ScheduledPost extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'scheduled_posts';
    
    protected $fillable = [
        'user_id', 'group_ids', 'content', 'schedule_times',
        'schedule_times_utc', 'user_timezone', 'advertiser'
    ];

    protected $casts = [
        'content' => 'array',
        'schedule_times' => 'array',
        'schedule_times_utc' => 'array',
        'advertiser' => 'array',
        'group_ids' => 'array'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function groups()
    {
        return $this->belongsToMany(Group::class, null, 'post_id', 'group_ids', '_id', '_id');
    }

    public function getGroupsAttribute()
    {
        if (!isset($this->attributes['group_ids']) || empty($this->attributes['group_ids'])) {
            return collect();
        }
        
        return Group::whereIn('_id', $this->attributes['group_ids'])->get();
    }

    public function logs()
    {
        return $this->hasMany(PostLog::class, 'post_id');
    }

    /**
     * Get pending (future) schedule times
     */
    public function getPendingScheduleTimes()
    {
        $now = Carbon::now('UTC');
        $pendingTimes = [];
        
        if (!$this->schedule_times_utc) {
            return $pendingTimes;
        }
        
        foreach ($this->schedule_times_utc as $index => $utcTime) {
            $timeCarbon = Carbon::parse($utcTime, 'UTC');
            if ($timeCarbon->isFuture()) {
                $pendingTimes[] = [
                    'index' => $index,
                    'utc_time' => $utcTime,
                    'user_time' => $this->schedule_times[$index] ?? $utcTime,
                    'carbon' => $timeCarbon
                ];
            }
        }
        
        return $pendingTimes;
    }

    /**
     * Get sent schedule times (from logs)
     */
    public function getSentScheduleTimes()
    {
        return $this->logs()
            ->where('status', 'sent')
            ->select('scheduled_time', 'sent_at', 'group_id')
            ->get()
            ->groupBy('scheduled_time');
    }

    /**
     * Check if a specific time has been sent to all groups
     */
    public function isTimeSentToAllGroups($scheduledTime)
    {
        $groupIds = $this->group_ids ?? [];
        $sentLogs = $this->logs()
            ->where('scheduled_time', $scheduledTime)
            ->where('status', 'sent')
            ->pluck('group_id')
            ->toArray();
        
        return count(array_intersect($groupIds, $sentLogs)) === count($groupIds);
    }

    /**
     * Get statistics for this post
     */
    public function getStatistics()
    {
        $sentLogs = $this->logs()->where('status', 'sent')->get();
        $failedLogs = $this->logs()->where('status', 'failed')->get();
        
        $totalScheduled = count($this->schedule_times_utc ?? []) * count($this->group_ids ?? []);
        
        return [
            'total_sent' => $sentLogs->count(),
            'total_failed' => $failedLogs->count(),
            'total_scheduled' => $totalScheduled,
            'success_rate' => $totalScheduled > 0 
                ? round(($sentLogs->count() / $totalScheduled) * 100, 2) 
                : 0,
            'pending_count' => count($this->getPendingScheduleTimes()) * count($this->group_ids ?? []),
            'sent_times' => $sentLogs->pluck('sent_at')->map(function($date) {
                return Carbon::parse($date)->timezone($this->user_timezone);
            }),
            'groups_count' => count($this->group_ids ?? [])
        ];
    }

    /**
     * Check if post has any pending sends
     */
    public function hasPendingSends()
    {
        return count($this->getPendingScheduleTimes()) > 0;
    }

    public static function boot()
    {
        parent::boot();
        
        static::creating(function ($post) {
            $post->convertScheduleTimesToUtc();
        });

        static::updating(function ($post) {
            // Always recalculate UTC times when schedule_times change
            if ($post->isDirty('schedule_times')) {
                $post->convertScheduleTimesToUtc();
            }
        });
    }

    /**
     * Convert user schedule times to UTC
     */
    private function convertScheduleTimesToUtc()
    {
        $userTimezone = $this->user_timezone ?: 'UTC';
        
        $this->schedule_times_utc = collect($this->schedule_times)
            ->map(function ($time) use ($userTimezone) {
                try {
                    // Parse time in user's timezone and convert to UTC
                    $carbonTime = Carbon::parse($time, $userTimezone);
                    return $carbonTime->utc()->format('Y-m-d H:i:s');
                } catch (\Exception $e) {
                    \Log::error("Error converting time {$time} from {$userTimezone} to UTC: " . $e->getMessage());
                    // Fallback: assume it's already UTC
                    return Carbon::parse($time)->format('Y-m-d H:i:s');
                }
            })
            ->toArray();
    }
}