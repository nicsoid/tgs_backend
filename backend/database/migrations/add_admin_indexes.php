<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // Add indexes for better admin query performance
        // Note: MongoDB indexes are created differently, this is a placeholder
        // You would run these commands in MongoDB directly:
        
        /*
        db.users.createIndex({ "telegram_id": 1 })
        db.users.createIndex({ "username": 1 })
        db.users.createIndex({ "settings.is_admin": 1 })
        db.users.createIndex({ "settings.is_banned": 1 })
        db.users.createIndex({ "subscription.plan": 1 })
        db.users.createIndex({ "auth_date": -1 })
        db.users.createIndex({ "created_at": -1 })
        
        db.scheduled_posts.createIndex({ "user_id": 1 })
        db.scheduled_posts.createIndex({ "status": 1 })
        db.scheduled_posts.createIndex({ "created_at": -1 })
        db.scheduled_posts.createIndex({ "group_ids": 1 })
        
        db.post_logs.createIndex({ "status": 1 })
        db.post_logs.createIndex({ "sent_at": -1 })
        db.post_logs.createIndex({ "post_id": 1 })
        db.post_logs.createIndex({ "group_id": 1 })
        
        db.groups.createIndex({ "telegram_id": 1 })
        db.groups.createIndex({ "username": 1 })
        db.groups.createIndex({ "member_count": -1 })
        
        db.user_groups.createIndex({ "user_id": 1, "group_id": 1 })
        db.user_groups.createIndex({ "user_id": 1, "is_admin": 1 })
        db.user_groups.createIndex({ "group_id": 1, "is_admin": 1 })
        */
    }

    public function down()
    {
        // Drop indexes if needed
    }
};