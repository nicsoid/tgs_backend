#!/bin/bash
# fix-queue-workers.sh - Fix queue worker restart loop

echo "🔧 FIXING QUEUE WORKER RESTART LOOP"
echo "==================================="

echo "The queue workers seem to be restarting constantly."
echo "This usually happens when:"
echo "1. The queue:work command fails immediately"
echo "2. Database connection issues"
echo "3. Invalid queue configuration"
echo ""

echo "1. CHECKING CURRENT QUEUE WORKER STATUS"
echo "======================================="
docker-compose ps queue-worker

echo ""
echo "2. CHECKING QUEUE WORKER LOGS (LAST 50 LINES)"
echo "=============================================="
docker-compose logs queue-worker --tail=50

echo ""
echo "3. TESTING QUEUE CONNECTION MANUALLY"
echo "===================================="
echo "Testing if queue:work command works at all:"
docker-compose exec backend php artisan queue:work --once --verbose

echo ""
echo "4. CHECKING REDIS CONNECTION"
echo "============================"
docker-compose exec backend php artisan tinker --execute="
try {
    \$redis = Redis::connection();
    \$ping = \$redis->ping();
    echo 'Redis ping: ' . \$ping;
    
    // Test queue operations
    \$redis->lpush('test_queue', 'test_job');
    \$job = \$redis->rpop('test_queue');
    echo 'Queue test: ' . (\$job === 'test_job' ? 'PASS' : 'FAIL');
} catch (Exception \$e) {
    echo 'Redis error: ' . \$e->getMessage();
}
"

echo ""
echo "5. CHECKING QUEUE CONFIGURATION"
echo "==============================="
docker-compose exec backend php artisan tinker --execute="
echo 'Queue connection: ' . config('queue.default');
echo 'Queue table: ' . config('queue.connections.database.table', 'N/A');
echo 'Redis host: ' . config('database.redis.default.host');
echo 'Redis port: ' . config('database.redis.default.port');
"

echo ""
echo "6. CREATING FIXED DOCKER-COMPOSE OVERRIDE"
echo "=========================================="

cat > docker-compose.override.yml << 'EOF'
# docker-compose.override.yml - Fixed queue workers
version: "3.8"

services:
  # Fixed Queue Workers - Better command and error handling
  queue-worker:
    command: >
      sh -c "
        echo 'Queue Worker Starting...'
        
        # Wait for dependencies
        until nc -z mongodb 27017; do
          echo 'Waiting for MongoDB...'
          sleep 2
        done
        
        until nc -z redis 6379; do
          echo 'Waiting for Redis...'
          sleep 2
        done
        
        echo 'Dependencies ready, starting queue worker...'
        
        # Test database connection first
        php artisan tinker --execute='
          try {
            DB::connection(\"mongodb\")->listCollections();
            echo \"MongoDB: OK\";
          } catch (Exception \$e) {
            echo \"MongoDB Error: \" . \$e->getMessage();
            exit(1);
          }
        '
        
        # Test Redis connection
        php artisan tinker --execute='
          try {
            Redis::connection()->ping();
            echo \"Redis: OK\";
          } catch (Exception \$e) {
            echo \"Redis Error: \" . \$e->getMessage();
            exit(1);
          }
        '
        
        echo 'Starting queue worker loop...'
        
        # Better queue worker with restart capability
        while true; do
          echo '[Queue] Starting worker at: $(date)'
          php artisan queue:work redis \
            --sleep=3 \
            --tries=3 \
            --max-time=3600 \
            --memory=512 \
            --timeout=60 \
            --verbose \
            --stop-when-empty || {
            echo '[Queue] Worker stopped, waiting 5 seconds before restart...'
            sleep 5
          }
          
          echo '[Queue] Worker cycle completed at: $(date)'
          sleep 1
        done
      "
    deploy:
      replicas: 1  # Reduced to 1 for debugging

  # Fixed Scheduler - Better logging and error handling
  scheduler:
    command: >
      sh -c "
        echo 'Scheduler Starting...'
        
        # Wait for backend to be ready
        until curl -f http://backend:8000/health >/dev/null 2>&1; do
          echo 'Waiting for backend...'
          sleep 5
        done
        
        echo 'Backend ready, starting scheduler...'
        
        # Show what commands are scheduled
        echo 'Scheduled commands:'
        php artisan schedule:list
        
        echo 'Starting scheduler loop...'
        while true; do
          echo '[Scheduler] Running at: $(date)'
          php artisan schedule:run --verbose --no-interaction
          echo '[Scheduler] Completed at: $(date)'
          sleep 60
        done
      "
EOF

echo "✅ Created docker-compose.override.yml with fixed configuration"

echo ""
echo "7. APPLYING FIXES"
echo "================"
echo "Stopping and restarting with fixed configuration..."
docker-compose down
docker-compose up -d

echo ""
echo "Waiting for services to start..."
sleep 15

echo ""
echo "8. TESTING FIXES"
echo "================"
echo "Queue worker status:"
docker-compose ps queue-worker

echo ""
echo "Queue worker logs (last 20 lines):"
docker-compose logs queue-worker --tail=20

echo ""
echo "Scheduler logs (last 10 lines):"
docker-compose logs scheduler --tail=10

echo ""
echo "9. MANUAL TESTS"
echo "=============="
echo "Testing queue manually:"
docker-compose exec backend php artisan queue:work --once --timeout=30

echo ""
echo "Testing scheduler manually:"
docker-compose exec backend php artisan schedule:run --verbose

echo ""
echo "🎯 VERIFICATION STEPS"
echo "===================="
echo "1. Queue workers should no longer restart constantly"
echo "2. Scheduler should show scheduled commands"
echo "3. Both should connect to MongoDB and Redis successfully"
echo ""
echo "If still having issues:"
echo "- Check: docker-compose logs backend"
echo "- Check: docker-compose logs mongodb"
echo "- Check: docker-compose logs redis"
echo ""
echo "✅ Queue worker fixes applied!"