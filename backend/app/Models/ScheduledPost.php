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
        'user_id', 'group_id', 'content', 'schedule_times',
        'schedule_times_utc', 'user_timezone', 'advertiser', 
        'status', 'send_count', 'total_scheduled'
    ];

    protected $casts = [
        'content' => 'array',
        'schedule_times' => 'array',
        'schedule_times_utc' => 'array',
        'advertiser' => 'array'
    ];

    protected $attributes = [
        'send_count' => 0,
        'status' => 'pending'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function group()
    {
        return $this->belongsTo(Group::class);
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
            'status' => $this->status
        ];
    }

    public static function boot()
    {
        parent::boot();
        
        static::creating(function ($post) {
            // Convert schedule times to UTC
            $post->schedule_times_utc = collect($post->schedule_times)
                ->map(function ($time) use ($post) {
                    return Carbon::parse($time, $post->user_timezone)
                        ->setTimezone('UTC')
                        ->toDateTimeString();
                })
                ->toArray();
            
            $post->total_scheduled = count($post->schedule_times);
        });
    }
}
