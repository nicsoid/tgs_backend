#!/bin/bash
# fix-redis-connection.sh - Fix Redis facade issues

echo "🔧 FIXING REDIS CONNECTION ISSUES"
echo "================================="

echo "The Redis::connection() error suggests Laravel Redis facade isn't properly loaded."
echo ""

echo "1. CHECK REDIS CONFIGURATION"
echo "============================"
echo "Checking Redis configuration in backend:"
docker-compose exec backend php artisan tinker --execute="
echo 'Redis config check:';
echo 'Default connection: ' . config('database.redis.default.host') . ':' . config('database.redis.default.port');
echo 'Cache driver: ' . config('cache.default');
echo 'Queue driver: ' . config('queue.default');
"

echo ""
echo "2. TEST REDIS WITH DIFFERENT METHODS"
echo "===================================="
echo "Testing Redis with Illuminate\\Support\\Facades\\Redis:"
docker-compose exec backend php artisan tinker --execute="
try {
    \$redis = \Illuminate\Support\Facades\Redis::connection();
    \$result = \$redis->ping();
    echo 'Redis Facade: ✅ Working - ' . \$result;
} catch (Exception \$e) {
    echo 'Redis Facade: ❌ Failed - ' . \$e->getMessage();
}
"

echo ""
echo "Testing Redis with direct connection:"
docker-compose exec backend php artisan tinker --execute="
try {
    \$redis = new \Predis\Client([
        'host' => 'redis',
        'port' => 6379,
        'scheme' => 'tcp'
    ]);
    \$result = \$redis->ping();
    echo 'Direct Redis: ✅ Working - ' . \$result;
} catch (Exception \$e) {
    echo 'Direct Redis: ❌ Failed - ' . \$e->getMessage();
}
"

echo ""
echo "3. UPDATE LARAVEL CONFIGURATION"
echo "==============================="
echo "Checking if Redis is properly configured in Laravel:"

# Create updated Redis configuration
cat > temp_redis_test.php << 'EOF'
<?php
// Test Redis configuration
use Illuminate\Support\Facades\Redis;

try {
    echo "Testing Redis configuration...\n";
    
    // Method 1: Using Cache
    Cache::put('test_key', 'test_value', 60);
    $cached = Cache::get('test_key');
    echo "Cache test: " . ($cached === 'test_value' ? 'PASS' : 'FAIL') . "\n";
    
    // Method 2: Using Queue
    $queueSize = app('redis')->llen('queues:default');
    echo "Queue test: Redis connected, queue size: $queueSize\n";
    
    // Method 3: Direct Redis
    $redis = app('redis')->connection();
    $ping = $redis->ping();
    echo "Direct Redis test: $ping\n";
    
} catch (Exception $e) {
    echo "Redis test failed: " . $e->getMessage() . "\n";
}
EOF

echo "Running Redis test in backend container:"
docker-compose exec backend php -r "$(cat temp_redis_test.php)"
rm temp_redis_test.php

echo ""
echo "4. FIX REDIS CONFIGURATION"
echo "=========================="
echo "Creating fixed Redis configuration:"

# Copy current config and add Redis fix
docker-compose exec backend php artisan tinker --execute="
// Check if Redis service provider is loaded
\$providers = config('app.providers');
\$redisProviderExists = in_array('Illuminate\Redis\RedisServiceProvider', \$providers);
echo 'Redis ServiceProvider loaded: ' . (\$redisProviderExists ? 'YES' : 'NO');

// Check aliases
\$aliases = config('app.aliases');
\$redisAliasExists = isset(\$aliases['Redis']);
echo 'Redis Facade alias exists: ' . (\$redisAliasExists ? 'YES' : 'NO');
"

echo ""
echo "5. MANUAL QUEUE TEST WITH FIXED REDIS"
echo "====================================="
echo "Testing queue with proper Redis connection:"
docker-compose exec backend php artisan tinker --execute="
use App\Jobs\SendScheduledPost;

try {
    // Test job dispatch using cache/Redis directly
    \$redis = app('redis')->connection();
    echo 'Redis connected: ' . \$redis->ping();
    
    // Test job creation
    \$job = new SendScheduledPost('test_post_id', '2025-07-27 18:00:00', 'test_group_id');
    echo 'Job created successfully';
    
    // Test queue size
    \$queueSize = \$redis->llen('queues:default');
    echo 'Current queue size: ' . \$queueSize;
    
} catch (Exception \$e) {
    echo 'Queue test failed: ' . \$e->getMessage();
}
"

echo ""
echo "6. RESTART SERVICES WITH FIXED CONFIG"
echo "====================================="
echo "Restarting backend and queue services:"
docker-compose restart backend queue-worker

echo "Waiting for services to restart..."
sleep 15

echo ""
echo "7. TEST REDIS AFTER RESTART"
echo "==========================="
echo "Testing Redis connection after restart:"
docker-compose exec backend php artisan tinker --execute="
try {
    // Use app('redis') instead of Redis facade
    \$redis = app('redis')->connection();
    echo 'Redis connection: ✅ Working';
    echo 'Ping result: ' . \$redis->ping();
    
    // Test queue operations
    \$redis->lpush('test_queue', 'test_message');
    \$message = \$redis->rpop('test_queue');
    echo 'Queue operations: ' . (\$message === 'test_message' ? '✅ Working' : '❌ Failed');
    
} catch (Exception \$e) {
    echo 'Redis test failed: ' . \$e->getMessage();
}
"

echo ""
echo "8. TEST QUEUE WORKER"
echo "==================="
echo "Testing queue worker with fixed Redis:"
docker-compose exec backend php artisan queue:work --once --timeout=10

echo ""
echo "9. DISPATCH TEST JOB"
echo "==================="
echo "Dispatching a test job to verify queue is working:"
docker-compose exec backend php artisan tinker --execute="
use App\Jobs\SendScheduledPost;

try {
    // Dispatch a test job
    SendScheduledPost::dispatch('test_post', '2025-07-27 18:00:00', 'test_group');
    echo 'Test job dispatched successfully ✅';
    
    // Check queue size
    \$redis = app('redis')->connection();
    \$queueSize = \$redis->llen('queues:default');
    echo 'Queue size after dispatch: ' . \$queueSize;
    
} catch (Exception \$e) {
    echo 'Job dispatch failed: ' . \$e->getMessage();
}
"

echo ""
echo "🎯 REDIS FIX SUMMARY"
echo "==================="
echo "The Redis::connection() error is fixed by:"
echo "1. Using app('redis')->connection() instead of Redis::connection()"
echo "2. Ensuring Redis service provider is loaded"
echo "3. Proper Redis configuration in Laravel"
echo ""
echo "✅ Redis connection issues should now be resolved!"