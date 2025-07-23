#!/bin/bash
# local-frontend-dev.sh - Setup for local frontend + Docker backend services

echo "🚀 Setting up Local Frontend + Docker Backend Development"
echo "========================================================"

echo "This setup will run:"
echo "  🐳 Docker: MongoDB, Redis, Backend, Queue Workers, Scheduler"
echo "  💻 Local: Frontend (npm start)"
echo "  🌐 Ngrok: Tunnel to local frontend for Telegram Web App"
echo ""

# 1. Create development docker-compose (backend services only)
echo "📝 Creating backend-only docker-compose..."

cat > docker-compose.dev.yml << 'EOF'
# docker-compose.dev.yml - Backend services only for local frontend development
version: "3.8"

services:
  # MongoDB Database
  mongodb:
    image: mongo:7
    container_name: telegram-scheduler-mongodb
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
      retries: 5
      start_period: 40s

  # Redis Cache & Queue
  redis:
    image: redis:7-alpine
    container_name: telegram-scheduler-redis
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
      start_period: 30s

  # Backend (Laravel API)
  backend:
    build:
      context: ./backend
      dockerfile: Dockerfile
      target: production
    container_name: telegram-scheduler-backend
    restart: unless-stopped
    ports:
      - "8000:8000"
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
      - SESSION_DRIVER=redis
      - REDIS_HOST=redis
      - REDIS_PORT=6379
      - REDIS_PASSWORD=
      - JWT_SECRET=fIXfC0pDOdYeYxRY35WcPYJgt07tXtfxGzRdRMlHMb4ytitTHbkT8YooO8w3Jxxj
      - JWT_TTL=60
      - JWT_REFRESH_TTL=20160
      - TELEGRAM_BOT_TOKEN=7779533338:AAH-B1-r4GQzJStkyC7Ecziip7ccgso5AOs
      - TELEGRAM_BOT_USERNAME=tgappy_bot
      - ADMIN_TELEGRAM_IDS=6941596189
      - CONTAINER_ROLE=app
      # CORS allows local frontend on localhost:3000
      - CORS_ALLOWED_ORIGINS=http://localhost:3000,https://68c6605bb77f.ngrok-free.app,http://localhost:8000
      - STRIPE_KEY=sk_test_51Rcqa9RqiHAAOQNt4Qlp9zmFKkjf78mRgjqjTveYVmZg7z8OpEvElH0qkJMMaNKvgtJwUMa8kxWzER7pRCwwDe5K00I41OXJuh
      - STRIPE_PUBLIC_KEY=pk_test_51Rcqa9RqiHAAOQNt6dQOzDxtKXI4hl3PjmdxnnOz6Y2ARZJ4zXE03frTz6FPhMbp6ZGMxERdRVi1xJMK0SW6i2Gn00NYOU3H8O
      - STRIPE_WEBHOOK_SECRET=whsec_6GmWJJhXEHNwVLwi79M3eLZFgNFzcPo8
      - STRIPE_PRICE_ID_PRO=price_1Rdv4sRqiHAAOQNtgBC15qcq
      - STRIPE_PRODUCT_ID_PRO=prod_SZ3Bv1lG9HoCLM
      - STRIPE_PRICE_ID_ULTRA=price_1RiLAnRqiHAAOQNt8JKhJKZM
      - STRIPE_PRODUCT_ID_ULTRA=prod_SdcPkOn7RC90lG
    volumes:
      - ./backend:/var/www/html
      - backend_storage:/var/www/html/storage
      - backend_uploads:/var/www/html/storage/app/public
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
      retries: 10
      start_period: 180s

  # Queue Workers
  queue-worker:
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
      - CONTAINER_ROLE=queue
    volumes:
      - ./backend:/var/www/html
      - backend_storage:/var/www/html/storage
      - backend_uploads:/var/www/html/storage/app/public
    networks:
      - telegram-scheduler
    depends_on:
      backend:
        condition: service_healthy
    deploy:
      replicas: 2
    command: php artisan queue:work redis --sleep=1 --tries=3 --max-time=3600 --memory=512

  # Scheduler (Cron Jobs)
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
        while true; do
          php artisan schedule:run --verbose --no-interaction
          sleep 60
        done
      "

  # Redis UI (Optional - for monitoring)
  redis-commander:
    image: rediscommander/redis-commander:latest
    container_name: telegram-scheduler-redis-ui
    restart: unless-stopped
    ports:
      - "8081:8081"
    environment:
      - REDIS_HOSTS=local:redis:6379
    networks:
      - telegram-scheduler
    depends_on:
      - redis
    profiles:
      - monitoring

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
  backend_uploads:
    driver: local
EOF

# 2. Create frontend environment for local development
echo "📝 Creating frontend environment..."

if [ ! -d frontend ]; then
    echo "❌ Frontend directory not found"
    exit 1
fi

cat > frontend/.env.local << 'EOF'
# Frontend Local Development Environment
REACT_APP_API_URL=http://localhost:8000
REACT_APP_TELEGRAM_BOT_USERNAME=tgappy_bot
REACT_APP_FRONTEND_URL=https://68c6605bb77f.ngrok-free.app

# Development settings
GENERATE_SOURCEMAP=true
FAST_REFRESH=true
EOF

# 3. Create development commands
echo "📝 Creating development commands..."

cat > dev-local.sh << 'EOF'
#!/bin/bash
# dev-local.sh - Local frontend development commands

case "$1" in
    "start")
        echo "🚀 Starting backend services with Docker..."
        docker-compose -f docker-compose.dev.yml up -d
        
        echo "⏳ Waiting for services to be ready..."
        sleep 30
        
        echo "📊 Backend services status:"
        docker-compose -f docker-compose.dev.yml ps
        
        echo "🧪 Testing backend health..."
        if curl -f -s http://localhost:8000/health; then
            echo "✅ Backend is ready!"
        else
            echo "⚠️ Backend not ready yet, check logs"
        fi
        
        echo ""
        echo "📋 Next steps:"
        echo "1. Start frontend: cd frontend && npm start"
        echo "2. Start ngrok: ngrok http 3000"
        echo "3. Update ngrok URL: ./env-manager.sh update-ngrok <url>"
        echo "4. Set webhook: ./set-webhook.sh"
        ;;
        
    "frontend")
        echo "🌐 Starting frontend locally..."
        cd frontend
        
        if [ ! -d node_modules ]; then
            echo "📦 Installing dependencies..."
            npm install
        fi
        
        echo "🚀 Starting React development server..."
        npm start
        ;;
        
    "stop")
        echo "🛑 Stopping backend services..."
        docker-compose -f docker-compose.dev.yml down
        ;;
        
    "logs")
        service="${2:-backend}"
        echo "📄 Showing logs for $service..."
        docker-compose -f docker-compose.dev.yml logs -f --tail=50 $service
        ;;
        
    "shell")
        service="${2:-backend}"
        echo "🐚 Opening shell in $service..."
        docker-compose -f docker-compose.dev.yml exec $service bash
        ;;
        
    "status")
        echo "📊 Development Environment Status"
        echo "================================"
        
        echo "🐳 Docker services:"
        docker-compose -f docker-compose.dev.yml ps
        
        echo ""
        echo "🔗 Service health:"
        curl -f -s http://localhost:8000/health && echo "✅ Backend API" || echo "❌ Backend API"
        curl -f -s http://localhost:27017 && echo "✅ MongoDB" || echo "❌ MongoDB"
        curl -f -s http://localhost:6379 && echo "✅ Redis" || echo "❌ Redis"
        curl -f -s http://localhost:3000 && echo "✅ Frontend" || echo "❌ Frontend (not running locally)"
        
        echo ""
        echo "📱 Port usage:"
        echo "  3000: Frontend (local npm start)"
        echo "  8000: Backend API (Docker)"
        echo "  27017: MongoDB (Docker)"
        echo "  6379: Redis (Docker)"
        echo "  8081: Redis UI (Docker - optional)"
        ;;
        
    "test")
        echo "🧪 Testing complete development setup..."
        
        echo "Backend API:"
        curl -f http://localhost:8000/health || echo "❌ Backend not responding"
        
        echo "Frontend (if running):"
        curl -f -s http://localhost:3000 >/dev/null && echo "✅ Frontend responding" || echo "❌ Frontend not running (run: ./dev-local.sh frontend)"
        
        echo "Database connection:"
        docker-compose -f docker-compose.dev.yml exec -T backend php artisan tinker --execute="echo 'DB connection: '; try { DB::connection('mongodb')->listCollections(); echo 'OK'; } catch(Exception \$e) { echo 'Failed: ' . \$e->getMessage(); }" || echo "❌ Database test failed"
        ;;
        
    *)
        echo "🛠️ Local Frontend Development Commands"
        echo "====================================="
        echo ""
        echo "Usage: $0 [command]"
        echo ""
        echo "Commands:"
        echo "  start           - Start backend services (Docker)"
        echo "  frontend        - Start frontend (local npm start)"
        echo "  stop            - Stop backend services"
        echo "  status          - Show all services status"
        echo "  logs [service]  - View service logs"
        echo "  shell [service] - Access service shell"
        echo "  test            - Test complete setup"
        echo ""
        echo "Development Workflow:"
        echo "  1. ./dev-local.sh start        # Start backend"
        echo "  2. ./dev-local.sh frontend     # Start frontend"
        echo "  3. ngrok http 3000             # Expose frontend"
        echo ""
        echo "Architecture:"
        echo "  🐳 MongoDB, Redis, Backend, Queue, Scheduler: Docker"
        echo "  💻 Frontend: Local (npm start)"
        echo "  🌐 Ngrok: Tunnel to local frontend"
        ;;
esac
EOF

chmod +x dev-local.sh

echo "✅ Local development setup created!"
echo ""
echo "📋 Your development architecture:"
echo "  🐳 Docker Services:"
echo "     • MongoDB (localhost:27017)"
echo "     • Redis (localhost:6379)"  
echo "     • Backend API (localhost:8000)"
echo "     • Queue Workers (background)"
echo "     • Scheduler (background)"
echo ""
echo "  💻 Local Services:"
echo "     • Frontend (localhost:3000) - npm start"
echo "     • Ngrok tunnel for Telegram Web App"
echo ""
echo "🚀 Quick Start:"
echo "  1. ./dev-local.sh start      # Start backend services"
echo "  2. ./dev-local.sh frontend   # Start frontend locally"
echo "  3. ngrok http 3000           # Tunnel for Telegram"
echo ""
echo "📊 Check status: ./dev-local.sh status"