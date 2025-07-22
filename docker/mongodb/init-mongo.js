# docker/mongodb/init-mongo.js
// MongoDB Initialization Script

db = db.getSiblingDB('telegram_scheduler');

// Create indexes for better performance
db.post_logs.createIndex(
    { "post_id": 1, "group_id": 1, "scheduled_time": 1 }, 
    { unique: true, name: "post_group_time_unique" }
);

db.post_logs.createIndex(
    { "status": 1, "sent_at": -1 }, 
    { name: "status_sent_at" }
);

db.scheduled_posts.createIndex(
    { "status": 1, "schedule_times_utc": 1 }, 
    { name: "status_schedule_times" }
);

db.users.createIndex(
    { "telegram_id": 1 }, 
    { unique: true, name: "telegram_id_unique" }
);

db.groups.createIndex(
    { "telegram_id": 1 }, 
    { unique: true, name: "telegram_id_unique" }
);

print('Database initialized with indexes');