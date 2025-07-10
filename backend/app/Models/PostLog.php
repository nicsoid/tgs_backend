<?php
// app/Models/PostLog.php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;


class PostLog extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'post_logs';
    
    protected $fillable = [
        'post_id', 'scheduled_time', 'scheduled_time_utc',
        'sent_at', 'status', 'telegram_message_id', 'error_message'
    ];

    protected $casts = [
        'scheduled_time' => 'datetime',
        'scheduled_time_utc' => 'datetime',
        'sent_at' => 'datetime'
    ];

    public function post()
    {
        return $this->belongsTo(ScheduledPost::class, 'post_id');
    }
}
