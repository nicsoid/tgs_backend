<?php
// app/Models/PostLog.php - Enhanced with Performance Indexes
namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class PostLog extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'post_logs';
    
    protected $fillable = [
        'post_id', 'group_id', 'scheduled_time', 'scheduled_time_utc',
        'sent_at', 'status', 'telegram_message_id', 'error_message',
        'content_sent', 'retry_count', 'processing_duration', 'error_details'
    ];

    protected $casts = [
        'scheduled_time' => 'datetime',
        'scheduled_time_utc' => 'datetime',
        'sent_at' => 'datetime',
        'content_sent' => 'array',
        'error_details' => 'array'
    ];

    protected $indexes = [
        // Compound index for duplicate prevention (unique)
        [
            'keys' => ['post_id' => 1, 'group_id' => 1, 'scheduled_time' => 1],
            'options' => ['unique' => true]
        ],
        // Performance indexes
        ['keys' => ['status' => 1, 'sent_at' => -1]],
        ['keys' => ['post_id' => 1, 'status' => 1]],
        ['keys' => ['sent_at' => -1]],
        ['keys' => ['group_id' => 1, 'sent_at' => -1]]
    ];

    public function post()
    {
        return $this->belongsTo(ScheduledPost::class, 'post_id');
    }

    public function group()
    {
        return $this->belongsTo(Group::class, 'group_id');
    }

    // Efficient scope for duplicate checking
    public function scopeForPostGroupTime($query, $postId, $groupId, $scheduledTime)
    {
        return $query->where('post_id', $postId)
                    ->where('group_id', $groupId)
                    ->where('scheduled_time', $scheduledTime);
    }

    // Get processing statistics
    public static function getProcessingStats($period = '1 hour')
    {
        $since = now()->sub($period);
        
        return [
            'total_processed' => static::where('sent_at', '>=', $since)->count(),
            'successful' => static::where('sent_at', '>=', $since)->where('status', 'sent')->count(),
            'failed' => static::where('sent_at', '>=', $since)->where('status', 'failed')->count(),
            'avg_processing_time' => static::where('sent_at', '>=', $since)->avg('processing_duration'),
            'success_rate' => static::where('sent_at', '>=', $since)->count() > 0 
                ? round((static::where('sent_at', '>=', $since)->where('status', 'sent')->count() / 
                        static::where('sent_at', '>=', $since)->count()) * 100, 2)
                : 0
        ];
    }
}