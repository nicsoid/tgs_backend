// Database Indexes Setup Script
// database/scripts/setup_indexes.js (MongoDB)
/*
// Run this in MongoDB shell or via artisan command

// PostLog indexes for performance
db.post_logs.createIndex(
    { "post_id": 1, "group_id": 1, "scheduled_time": 1 }, 
    { unique: true, name: "post_group_time_unique" }
);

db.post_logs.createIndex(
    { "status": 1, "sent_at": -1 }, 
    { name: "status_sent_at" }
);

db.post_logs.createIndex(
    { "post_id": 1, "status": 1 }, 
    { name: "post_status" }
);

db.post_logs.createIndex(
    { "sent_at": -1 }, 
    { name: "sent_at_desc" }
);

db.post_logs.createIndex(
    { "group_id": 1, "sent_at": -1 }, 
    { name: "group_sent_at" }
);

// ScheduledPost indexes
db.scheduled_posts.createIndex(
    { "status": 1, "schedule_times_utc": 1 }, 
    { name: "status_schedule_times" }
);

db.scheduled_posts.createIndex(
    { "user_id": 1, "status": 1 }, 
    { name: "user_status" }
);

db.scheduled_posts.createIndex(
    { "created_at": -1 }, 
    { name: "created_at_desc" }
);

// User indexes
db.users.createIndex(
    { "telegram_id": 1 }, 
    { unique: true, name: "telegram_id_unique" }
);

// Group indexes
db.groups.createIndex(
    { "telegram_id": 1 }, 
    { unique: true, name: "telegram_id_unique" }
);

db.user_groups.createIndex(
    { "user_id": 1, "group_id": 1 }, 
    { unique: true, name: "user_group_unique" }
);

db.user_groups.createIndex(
    { "user_id": 1, "is_admin": 1 }, 
    { name: "user_admin" }
);
*/
