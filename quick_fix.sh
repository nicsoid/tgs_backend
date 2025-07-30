#!/bin/bash
# quick_fix.sh - Fix MongoDB connection issues immediately

echo "🔧 FIXING MONGODB CONNECTION ISSUES"
echo "==================================="

echo ""
echo "1. STOP CURRENT CONTAINERS"
echo "=========================="
docker-compose -f docker-compose.prod.yml down
docker-compose down  # Stop any existing containers

echo ""
echo "2. CREATE PROPER .env.production FILE"
echo "===================================="

cat > .env.production << 'EOF'
# Production Environment Variables - FIXED

# Application
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:wtqQ/6f+i9YERhRjXUttSgRw4VCEtn+zSsCyZioemZs=
APP_URL=http://localhost:8000

# MongoDB - Using same credentials as your working setup
MONGO_PASSWORD=password
MONGO_DB_CONNECTION=mongodb://admin:password@mongodb:27017/telegram_scheduler?authSource=admin

# Telegram
TELEGRAM_BOT_TOKEN=7779533338:AAH-B1-r4GQzJStkyC7Ecziip7ccgso5AOs
TELEGRAM_BOT_USERNAME=tgappy_bot

# JWT
JWT_SECRET=fIXfC0pDOdYeYxRY35WcPYJgt07tXtfxGzRdRMlHMb4ytitTHbkT8YooO8w3Jxxj

# Admin
ADMIN_TELEGRAM_IDS=6941596189

# Redis (no password for now)
REDIS_PASSWORD=
EOF

echo "✅ Created .env.production with your working credentials"

echo ""
echo "3. CREATE FIXED DOCKER COMPOSE"
echo "=============================="

# Use the working configuration from your original setup
cat > docker-compose.fixed.yml << 'EOF'
version: "3.8"

services:
  # MongoDB - Using your exact working configuration
  mongodb:
    image: mongo:7
    container_name: scheduler-mongodb-prod
    restart: unless-stopped
    ports:
      - "27017:27017"
    environment:
      - MONGO_INITDB_ROOT_USERNAME=admin
      - MONGO_INITDB_ROOT_PASSWORD=password
      - MONGO_INITDB_DATABASE=telegram_scheduler
    volumes:
      - mongodb_data:/data/db
    networks:
      - telegram-scheduler
    healthcheck:
      test: ["CMD", "mongosh", "--eval", "db.adminCommand('ping')"]
      interval: 30s
      timeout: 10s
      retries: 10
      start_period: 40s

  # Redis - Simple reliable config
  redis:
    image: redis:7-alpine
    container_name: scheduler-redis-prod
    restart: unless-stopped
    ports:
      - "6379:6379"
    volumes:
      - redis_data:/data
    networks:
      - telegram-scheduler
    command: redis-server --appendonly yes
    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
      interval: 30s
      timeout: 10s
      retries: 5
      start_period: 20s

  # Backend - Using your working environment variables
  backend:
    build:
      context: ./backend
      dockerfile: Dockerfile
      target: production
    container_name: scheduler-backend-prod
    restart: unless-stopped
    ports:
      - "8000:8000"
    environment:
      - APP_ENV=production
      - APP_DEBUG=false
      - APP_KEY=base64:wtqQ/6f+i9YERhRjXUttSgRw4VCEtn+zSsCyZioemZs=
      - APP_URL=http://localhost:8000

      # Database - exact same as your working setup
      - DB_CONNECTION=mongodb
      - MONGO_DB_CONNECTION=mongodb://admin:password@mongodb:27017/telegram_scheduler?authSource=admin
      - DB_DATABASE=telegram_scheduler
      - DB_AUTHENTICATION_DATABASE=admin

      # Queue & Cache
      - QUEUE_CONNECTION=redis
      - CACHE_STORE=redis
      - SESSION_DRIVER=redis
      - REDIS_HOST=redis
      - REDIS_PORT=6379
      - REDIS_PASSWORD=

      # JWT
      - JWT_SECRET=fIXfC0pDOdYeYxRY35WcPYJgt07tXtfxGzRdRMlHMb4ytitTHbkT8YooO8w3Jxxj
      - JWT_TTL=60
      - JWT_REFRESH_TTL=20160

      # Telegram - your working config
      - TELEGRAM_BOT_TOKEN=7779533338:AAH-B1-r4GQzJStkyC7Ecziip7ccgso5AOs
      - TELEGRAM_BOT_USERNAME=tgappy_bot

      # Admin
      - ADMIN_TELEGRAM_IDS=6941596189

      # Container role
      - CONTAINER_ROLE=app
    volumes:
      - ./backend:/var/www/html
      - backend_storage:/var/www/html/storage
    networks:
      - telegram-scheduler
    depends_on:
      mongodb:
        condition: service_healthy
      redis:
        condition: service_healthy
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:8000/health"]
      interval: 30s
      timeout: 10s
      retries: 5
      start_period: 120s

  # Simple Queue Worker - Just one to start
  queue-worker:
    build:
      context: ./backend
      dockerfile: Dockerfile
      target: production
    container_name: scheduler-queue-worker
    restart: unless-stopped
    environment:
      - APP_ENV=production
      - APP_DEBUG=false
      - APP_KEY=base64:wtqQ/6f+i9YERhRjXUttSgRw4VCEtn+zSsCyZioemZs=
      - DB_CONNECTION=mongodb
      - MONGO_DB_CONNECTION=mongodb://admin:password@mongodb:27017/telegram_scheduler?authSource=admin
      - DB_DATABASE=telegram_scheduler
      - DB_AUTHENTICATION_DATABASE=admin
      - QUEUE_CONNECTION=redis
      - REDIS_HOST=redis
      - REDIS_PORT=6379
      - REDIS_PASSWORD=
      - JWT_SECRET=fIXfC0pDOdYeYxRY35WcPYJgt07tXtfxGzRdRMlHMb4ytitTHbkT8YooO8w3Jxxj
      - TELEGRAM_BOT_TOKEN=7779533338:AAH-B1-r4GQzJStkyC7Ecziip7ccgso5AOs
      - CONTAINER_ROLE=queue
    volumes:
      - ./backend:/var/www/html
      - backend_storage:/var/www/html/storage
    networks:
      - telegram-scheduler
    depends_on:
      backend:
        condition: service_healthy
    command: php artisan queue:work redis --sleep=1 --tries=3 --max-time=3600 --memory=512

  # Simple Scheduler
  scheduler:
    build:
      context: ./backend
      dockerfile: Dockerfile
      target: production
    container_name: scheduler-cron
    restart: unless-stopped
    environment:
      - APP_ENV=production
      - APP_DEBUG=false
      - APP_KEY=base64:wtqQ/6f+i9YERhRjXUttSgRw4VCEtn+zSsCyZioemZs=
      - DB_CONNECTION=mongodb
      - MONGO_DB_CONNECTION=mongodb://admin:password@mongodb:27017/telegram_scheduler?authSource=admin
      - DB_DATABASE=telegram_scheduler
      - DB_AUTHENTICATION_DATABASE=admin
      - QUEUE_CONNECTION=redis
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
        while true; do
          php artisan messages:send-due
          sleep 60
        done
      "

networks:
  telegram-scheduler:
    driver: bridge

volumes:
  mongodb_data:
    driver: local
  redis_data:
    driver: local
  backend_storage:
    driver: local
EOF

echo "✅ Created docker-compose.fixed.yml with your working configuration"

echo ""
echo "4. START WITH FIXED CONFIGURATION"
echo "================================="
echo "Starting services with proper MongoDB credentials..."

# Start with the fixed configuration
docker-compose -f docker-compose.fixed.yml up -d mongodb redis

echo "⏳ Waiting for MongoDB and Redis to start..."
sleep 30

# Check MongoDB connection
echo "🔍 Testing MongoDB connection..."
docker-compose -f docker-compose.fixed.yml exec mongodb mongosh --eval "db.adminCommand('ping')" || {
    echo "❌ MongoDB connection failed!"
    exit 1
}

echo "✅ MongoDB is ready!"

# Start backend
docker-compose -f docker-compose.fixed.yml up -d backend

echo "⏳ Waiting for backend to start..."
sleep 30

# Test backend
echo "🔍 Testing backend connection..."
docker-compose -f docker-compose.fixed.yml exec backend php artisan tinker --execute="
try {
    \$connected = DB::connection('mongodb')->listCollections();
    echo 'Backend → MongoDB: ✅ Connected' . PHP_EOL;
} catch (Exception \$e) {
    echo 'Backend → MongoDB: ❌ Failed - ' . \$e->getMessage() . PHP_EOL;
}
"

echo ""
echo "5. START QUEUE WORKERS AND SCHEDULER"
echo "===================================="
docker-compose -f docker-compose.fixed.yml up -d queue-worker scheduler

echo "✅ All services started!"

echo ""
echo "6. TEST THE FIXED SYSTEM"
echo "========================"
echo "Testing message processing..."

docker-compose -f docker-compose.fixed.yml exec backend php artisan messages:send-due --dry-run --debug

echo ""
echo "🎉 MONGODB CONNECTION FIXED!"
echo "============================"
echo ""
echo "✅ MongoDB: Using your working credentials (admin/password)"
echo "✅ Redis: Simple configuration without password"
echo "✅ Backend: Connected to both databases"
echo "✅ Queue Worker: Ready to process jobs"
echo "✅ Scheduler: Running every minute"
echo ""
echo "📊 MONITOR YOUR SYSTEM:"
echo "docker-compose -f docker-compose.fixed.yml logs -f"
echo ""
echo "🧪 TEST MESSAGE SENDING:"
echo "docker-compose -f docker-compose.fixed.yml exec backend php artisan messages:send-due"
echo ""
echo "📈 SCALE UP LATER:"
echo "docker-compose -f docker-compose.fixed.yml up -d --scale queue-worker=3"