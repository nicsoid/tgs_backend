#!/bin/bash
# fix-scheduler-kernel.sh - Fix scheduler not detecting scheduled commands

echo "🔧 FIXING SCHEDULER KERNEL LOADING ISSUE"
echo "========================================"

echo "The issue: Scheduler container isn't detecting the scheduled commands"
echo "This means the Kernel.php file isn't being loaded properly in the scheduler container."
echo ""

echo "1. CLEAR ALL CACHES IN ALL CONTAINERS"
echo "====================================="
echo "Clearing caches in backend container:"
docker-compose exec backend php artisan config:clear
docker-compose exec backend php artisan route:clear
docker-compose exec backend php artisan cache:clear
docker-compose exec backend php artisan view:clear

echo ""
echo "Clearing caches in scheduler container:"
docker-compose exec scheduler php artisan config:clear
docker-compose exec scheduler php artisan route:clear
docker-compose exec scheduler php artisan cache:clear
docker-compose exec scheduler php artisan view:clear

echo ""
echo "2. CHECK KERNEL.PHP IN SCHEDULER CONTAINER"
echo "=========================================="
echo "Comparing Kernel.php files between containers:"
echo ""
echo "Backend container Kernel.php:"
docker-compose exec backend head -20 app/Console/Kernel.php

echo ""
echo "Scheduler container Kernel.php:"
docker-compose exec scheduler head -20 app/Console/Kernel.php

echo ""
echo "3. TEST SCHEDULE DETECTION IN SCHEDULER CONTAINER"
echo "================================================="
echo "Testing schedule:list in scheduler container:"
docker-compose exec scheduler php artisan schedule:list

echo ""
echo "4. RESTART SCHEDULER WITH FRESH CACHE"
echo "====================================="
echo "Restarting scheduler container:"
docker-compose restart scheduler

echo "Waiting for scheduler to start..."
sleep 10

echo ""
echo "Testing again after restart:"
docker-compose exec scheduler php artisan schedule:list

echo ""
echo "5. MANUAL COMMAND TEST IN SCHEDULER CONTAINER"
echo "============================================="
echo "Testing if posts:process-scheduled works in scheduler container:"
docker-compose exec scheduler php artisan posts:process-scheduled --dry-run

echo ""
echo "6. CREATE SIMPLIFIED DOCKER-COMPOSE SCHEDULER"
echo "=============================================="
echo "Creating a new scheduler configuration that ensures proper loading:"

cat > docker-compose.scheduler-fix.yml << 'EOF'
# docker-compose.scheduler-fix.yml - Fixed scheduler configuration
version: "3.8"

services:
  # Fixed Scheduler with proper command detection
  scheduler:
    build:
      context: ./backend
      dockerfile: Dockerfile
      target: production
    restart: unless-stopped
    environment:
      - APP_ENV=local
      - APP_DEBUG=true
      - APP_KEY=base64:wtqQ/6f+i9YERhRjXUttSgRw4VCEtn+zSsCyZioemZs=
      - APP_URL=http://localhost:8000
      - DB_CONNECTION=mongodb
      - MONGO_DB_CONNECTION=mongodb://admin:password@mongodb:27017/telegram_scheduler?authSource=admin
      - DB_DATABASE=telegram_scheduler
      - DB_AUTHENTICATION_DATABASE=admin
      - QUEUE_CONNECTION=redis
      - CACHE_STORE=redis
      - REDIS_HOST=redis
      - REDIS_PORT=6379
      - REDIS_PASSWORD=
      - JWT_SECRET=fIXfC0pDOdYeYxRY35WcPYJgt07tXtfxGzRdRMlHMb4ytitTHbkT8YooO8w3Jxxj
      - TELEGRAM_BOT_TOKEN=7779533338:AAH-B1-r4GQzJStkyC7Ecziip7ccgso5AOs
      - CONTAINER_ROLE=scheduler
    volumes:
      - ./backend:/var/www/html
      - backend_storage:/var/www/html/storage
    networks:
      - telegram-scheduler
    depends_on:
      backend:
        condition: service_healthy
    command: >
      sh -c "
        echo 'Fixed Scheduler Starting...'
        
        # Wait for backend
        until curl -f http://backend:8000/health >/dev/null 2>&1; do
          echo 'Waiting for backend...'
          sleep 5
        done
        
        echo 'Backend ready, clearing caches...'
        php artisan config:clear
        php artisan route:clear
        php artisan cache:clear
        
        echo 'Testing command registration:'
        php artisan list | grep 'posts:'
        
        echo 'Testing schedule detection:'
        php artisan schedule:list
        
        echo 'Starting scheduler loop...'
        while true; do
          echo '[Scheduler] Running at: $(date)'
          
          # Clear cache before each run to ensure fresh config
          php artisan config:clear >/dev/null 2>&1
          
          php artisan schedule:run --verbose --no-interaction
          echo '[Scheduler] Completed at: $(date)'
          sleep 60
        done
      "

volumes:
  backend_storage:
    external: true

networks:
  telegram-scheduler:
    external: true
EOF

echo "✅ Created fixed scheduler configuration"

echo ""
echo "7. APPLY SCHEDULER FIX"
echo "====================="
echo "Applying the scheduler fix:"
docker-compose -f docker-compose.yml -f docker-compose.scheduler-fix.yml up -d scheduler

echo ""
echo "Waiting for scheduler to start with new configuration..."
sleep 15

echo ""
echo "8. TEST FIXED SCHEDULER"
echo "======================"
echo "Testing scheduler with new configuration:"
docker-compose logs scheduler --tail=20

echo ""
echo "Testing schedule detection:"
docker-compose exec scheduler php artisan schedule:list

echo ""
echo "9. MANUAL SCHEDULER RUN"
echo "======================"
echo "Running scheduler manually to test:"
docker-compose exec scheduler php artisan schedule:run --verbose

echo ""
echo "🎯 EXPECTED RESULTS"
echo "=================="
echo "After this fix:"
echo "1. schedule:list should show 'posts:process-scheduled' every minute"
echo "2. Scheduler should run the command and find posts to process"
echo "3. Messages should be dispatched to queue"
echo ""
echo "If schedule:list still shows 'No scheduled tasks':"
echo "- There's a fundamental issue with Kernel.php loading"
echo "- Try recreating the Kernel.php file from scratch"
echo ""
echo "✅ Scheduler kernel fix applied!"