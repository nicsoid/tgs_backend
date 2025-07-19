<?php
// app/Models/ScheduledPost.php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;
use Carbon\Carbon;

class ScheduledPost extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'scheduled_posts';
    
    protected $fillable = [
        'user_id', 'group_ids', 'content', 'schedule_times',
        'schedule_times_utc', 'user_timezone', 'advertiser', 
        'status', 'send_count', 'total_scheduled', 'groups_count'
    ];

    protected $casts = [
        'content' => 'array',
        'schedule_times' => 'array',
        'schedule_times_utc' => 'array',
        'advertiser' => 'array',
        'group_ids' => 'array'
    ];

    protected $attributes = [
        'send_count' => 0,
        'status' => 'pending'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Relationship to get all groups this post is scheduled for
    public function groups()
    {
        return $this->belongsToMany(Group::class, null, 'post_id', 'group_ids', '_id', '_id');
    }

    // Helper method to get groups by IDs
    public function getGroupsAttribute()
    {
        if (!isset($this->attributes['group_ids']) || empty($this->attributes['group_ids'])) {
            return collect();
        }
        
        return Group::whereIn('_id', $this->attributes['group_ids'])->get();
    }

    // Backward compatibility - get first group as 'group'
    public function getGroupAttribute()
    {
        $groups = $this->groups;
        return $groups->first();
    }

    public function logs()
    {
        return $this->hasMany(PostLog::class, 'post_id');
    }

    public function getStatistics()
    {
        $logs = $this->logs()->where('status', 'sent')->get();
        
        return [
            'total_sent' => $logs->count(),
            'total_scheduled' => $this->total_scheduled,
            'success_rate' => $this->total_scheduled > 0 
                ? round(($logs->count() / $this->total_scheduled) * 100, 2) 
                : 0,
            'sent_times' => $logs->pluck('sent_at')->map(function($date) {
                return Carbon::parse($date)->timezone($this->user_timezone);
            }),
            'advertiser' => $this->advertiser,
            'status' => $this->status,
            'groups_count' => count($this->group_ids ?? [])
        ];
    }

    public static function boot()
    {
        parent::boot();
        
        static::creating(function ($post) {
            // Convert schedule times to UTC with proper timezone handling
            $userTimezone = $post->user_timezone ?: 'UTC';
            
            $post->schedule_times_utc = collect($post->schedule_times)
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
            
            // Calculate total scheduled messages (groups × schedule times)
            $groupCount = count($post->group_ids ?? []);
            $timeCount = count($post->schedule_times ?? []);
            $post->total_scheduled = $groupCount * $timeCount;
            $post->groups_count = $groupCount;
        });

        static::updating(function ($post) {
            // Recalculate if schedule times or groups changed
            if ($post->isDirty(['schedule_times', 'group_ids'])) {
                if ($post->isDirty('schedule_times')) {
                    $userTimezone = $post->user_timezone ?: 'UTC';
                    
                    $post->schedule_times_utc = collect($post->schedule_times)
                        ->map(function ($time) use ($userTimezone) {
                            try {
                                $carbonTime = Carbon::parse($time, $userTimezone);
                                return $carbonTime->utc()->format('Y-m-d H:i:s');
                            } catch (\Exception $e) {
                                \Log::error("Error converting time {$time} from {$userTimezone} to UTC: " . $e->getMessage());
                                return Carbon::parse($time)->format('Y-m-d H:i:s');
                            }
                        })
                        ->toArray();
                }
                
                $groupCount = count($post->group_ids ?? []);
                $timeCount = count($post->schedule_times ?? []);
                $post->total_scheduled = $groupCount * $timeCount;
                $post->groups_count = $groupCount;
            }
        });
    }
}