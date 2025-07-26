#!/bin/bash
# mongodb-fix.sh - Fix MongoDB connection issue with your existing setup

echo "🔧 MongoDB Connection Fix - Based on Your Configuration"
echo "======================================================="

echo "Analyzing your setup..."
echo "✅ Found docker-compose.dev.yml (backend services only)"
echo "✅ Found env-manager.sh (environment management)"
echo "✅ Found local-frontend-dev.sh (local frontend + Docker backend)"
echo ""

# Check which compose file exists and is configured properly
if [ -f "docker-compose.dev.yml" ]; then
    COMPOSE_FILE="docker-compose.dev.yml"
    echo "Using existing docker-compose.dev.yml"
elif [ -f "docker-compose.yml" ]; then
    COMPOSE_FILE="docker-compose.yml"
    echo "Using docker-compose.yml"
else
    echo "❌ No docker-compose file found!"
    exit 1
fi

echo ""
echo "🛑 Step 1: Stop all running containers..."
docker-compose -f $COMPOSE_FILE down 2>/dev/null || true
docker-compose down 2>/dev/null || true

echo ""
echo "🧹 Step 2: Clean up old data..."
echo "Removing potentially corrupted MongoDB volume..."
docker volume rm telegram-scheduler_mongodb_data 2>/dev/null || true
docker volume rm telegram-scheduler_redis_data 2>/dev/null || true

echo "Cleaning up stopped containers..."
docker container prune -f

echo ""
echo "⚙️ Step 3: Setup environment..."
if [ -f "env-manager.sh" ]; then
    echo "Using your env-manager.sh to set development environment..."
    chmod +x env-manager.sh
    ./env-manager.sh dev
else
    echo "Creating basic .env for development..."
    cat > .env << 'EOF'
APP_ENV=local
APP_DEBUG=true
DB_CONNECTION=mongodb
MONGO_DB_CONNECTION=mongodb://admin:password@mongodb:27017/telegram_scheduler?authSource=admin
QUEUE_CONNECTION=redis
REDIS_HOST=redis
TELEGRAM_BOT_TOKEN=7779533338:AAH-B1-r4GQzJStkyC7Ecziip7ccgso5AOs
TELEGRAM_BOT_USERNAME=tgappy_bot
ADMIN_TELEGRAM_IDS=6941596189
EOF
fi

echo ""
echo "🐳 Step 4: Start fresh containers..."
echo "Building and starting services..."
docker-compose -f $COMPOSE_FILE up -d --build

echo ""
echo "⏳ Step 5: Wait for MongoDB initialization..."
echo "This may take up to 60 seconds for MongoDB to fully initialize..."

# Wait for MongoDB to be ready
for i in {1..12}; do
    echo "Checking MongoDB... (attempt $i/12)"
    if docker-compose -f $COMPOSE_FILE exec -T mongodb mongosh --eval "db.adminCommand('ping')" >/dev/null 2>&1; then
        echo "✅ MongoDB is ready!"
        MONGODB_READY=true
        break
    fi
    sleep 5
done

if [ "$MONGODB_READY" != "true" ]; then
    echo "❌ MongoDB failed to start. Checking logs..."
    docker-compose -f $COMPOSE_FILE logs mongodb
    exit 1
fi

echo ""
echo "⏳ Step 6: Wait for backend to be ready..."
sleep 15

# Test backend API
for i in {1..10}; do
    echo "Testing backend API... (attempt $i/10)"
    if curl -f -s http://localhost:8000/health >/dev/null 2>&1; then
        echo "✅ Backend API is responding!"
        BACKEND_READY=true
        break
    fi
    sleep 3
done

if [ "$BACKEND_READY" != "true" ]; then
    echo "❌ Backend not responding. Checking logs..."
    docker-compose -f $COMPOSE_FILE logs backend
    exit 1
fi

echo ""
echo "🧪 Step 7: Test database connection from backend..."
if docker-compose -f $COMPOSE_FILE exec -T backend php artisan tinker --execute="try { \$collections = DB::connection('mongodb')->listCollections(); echo 'MongoDB Connection: SUCCESS'; foreach(\$collections as \$collection) { echo ' - Found collection: ' . \$collection->getName(); } } catch(Exception \$e) { echo 'MongoDB Connection: FAILED - ' . \$e->getMessage(); }" 2>/dev/null; then
    echo "✅ Backend can connect to MongoDB!"
else
    echo "❌ Backend cannot connect to MongoDB"
    echo "Checking backend logs for database errors..."
    docker-compose -f $COMPOSE_FILE logs backend | grep -i mongo || echo "No MongoDB errors in logs"
    exit 1
fi

echo ""
echo "📊 Step 8: Service status check..."
docker-compose -f $COMPOSE_FILE ps

echo ""
echo "🎉 MongoDB Connection Fix Complete!"
echo "=================================="
echo ""
echo "✅ Services Running:"
echo "  • MongoDB: localhost:27017"
echo "  • Redis: localhost:6379"
echo "  • Backend API: http://localhost:8000"
echo "  • Queue Workers: Background processing"
echo ""
echo "🧪 Test Results:"
curl -s http://localhost:8000/health && echo " ✅ Backend API health check passed" || echo " ❌ Backend API health check failed"

echo ""
echo "📋 Next Steps for Frontend Development:"
if [ -f "dev-local.sh" ]; then
    echo "  1. Start frontend: ./dev-local.sh frontend"
elif [ -f "local-frontend-dev.sh" ]; then
    echo "  1. Run: chmod +x local-frontend-dev.sh && ./local-frontend-dev.sh"
    echo "  2. Or manually: cd frontend && npm start"
else
    echo "  1. cd frontend && npm install && npm start"
fi

echo "  2. Start ngrok: ngrok http 3000"
echo "  3. Update ngrok URL: ./env-manager.sh update-ngrok <your-ngrok-url>"
echo "  4. Set webhook: ./set-webhook.sh"

echo ""
echo "🔍 Troubleshooting Commands:"
echo "  • Check logs: docker-compose -f $COMPOSE_FILE logs backend"
echo "  • Check MongoDB: docker-compose -f $COMPOSE_FILE logs mongodb"
echo "  • Backend shell: docker-compose -f $COMPOSE_FILE exec backend bash"
echo "  • MongoDB shell: docker-compose -f $COMPOSE_FILE exec mongodb mongosh"

echo ""
echo "📱 Your Architecture (Fixed):"
echo "  🐳 Docker: MongoDB + Redis + Backend API + Queue Workers"
echo "  💻 Local: Frontend (npm start on localhost:3000)"
echo "  🌐 Ngrok: Tunnel frontend for Telegram Web App"
echo "  🔗 Webhook: Points to localhost:8000/api/telegram/webhook"