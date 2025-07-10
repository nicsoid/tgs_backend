<?php 
// app/Models/Group.php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class Group extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'groups';
    
    protected $fillable = [
        'telegram_id', 'title', 'username', 'type',
        'photo_url', 'member_count'
    ];

    public function users()
    {
        return $this->belongsToMany(User::class, 'user_groups', 'group_id', 'user_id')
                    ->withPivot('is_admin', 'permissions', 'added_at', 'last_verified');
    }

    public function scheduledPosts()
    {
        return $this->hasMany(ScheduledPost::class);
    }
}
