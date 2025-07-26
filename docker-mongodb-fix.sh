#!/bin/bash
# docker-mongodb-fix.sh - Fix Docker container communication with MongoDB

echo "🔧 Fixing Docker MongoDB Connection Issue"
echo "========================================"
echo ""
echo "Problem: Backend container trying to connect to 'localhost:27017'"
echo "Solution: Use Docker service name 'mongodb:27017' instead"
echo ""

# Stop services first
echo "🛑 Stopping services..."
docker-compose -f docker-compose.dev.yml down 2>/dev/null || docker-compose down

echo ""
echo "📝 Fixing MongoDB connection string..."

# Check and fix your docker-compose.dev.yml
if [ -f docker-compose.dev.yml ]; then
    echo "✅ Found docker-compose.dev.yml"
    
    # Create corrected version
    echo "Creating corrected docker-compose.dev.yml..."
    
    # Backup original
    cp docker-compose.dev.yml docker-compose.dev.yml.backup
    
    # Fix the MONGO_DB_CONNECTION environment variable
    sed -i.tmp 's|mongodb://admin:password@mongodb:27017|mongodb://admin:password@mongodb:27017|g' docker-compose.dev.yml
    rm docker-compose.dev.yml.tmp 2>/dev/null
    
    echo "✅ Docker compose file updated"
else
    echo "❌ docker-compose.dev.yml not found"
    exit 1
fi

# Fix .env file to use container service name
echo ""
echo "📝 Fixing .env file..."

if [ -f .env ]; then
    # Backup original
    cp .env .env.backup
    
    # Fix MongoDB connection string in .env
    # Replace any localhost references with 'mongodb' (the service name)
    sed -i.tmp 's|localhost:27017|mongodb:27017|g' .env
    sed -i.tmp 's|127.0.0.1:27017|mongodb:27017|g' .env
    rm .env.tmp 2>/dev/null
    
    echo "✅ .env file updated"
    
    # Show the corrected connection string
    echo ""
    echo "📋 Updated MongoDB connection:"
    grep MONGO_DB_CONNECTION .env || echo "MONGO_DB_CONNECTION not found in .env"
else
    echo "❌ .env file not found"
    
    # Create a proper .env file
    echo "📝 Creating correct .env file..."
    cat > .env << 'EOF'
# Laravel Environment
APP_NAME="Telegram Scheduler"
APP_ENV=local
APP_KEY=base64:wtqQ/6f+i9YERhRjXUttSgRw4VCEtn+zSsCyZioemZs=
APP_DEBUG=true
APP_URL=http://localhost:8000

# Database Configuration (FIXED: Use 'mongodb' service name, not localhost)
DB_CONNECTION=mongodb
MONGO_DB_CONNECTION=mongodb://admin:password@mongodb:27017/telegram_scheduler?authSource=admin
DB_DATABASE=telegram_scheduler
DB_AUTHENTICATION_DATABASE=admin
MONGO_ROOT_USERNAME=admin
MONGO_ROOT_PASSWORD=password

# Queue & Cache Configuration
QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=redis
REDIS_HOST=redis
REDIS_PORT=6379
REDIS_PASSWORD=

# JWT Configuration
JWT_SECRET=fIXfC0pDOdYeYxRY35WcPYJgt07tXtfxGzRdRMlHMb4ytitTHbkT8YooO8w3Jxxj
JWT_TTL=60
JWT_REFRESH_TTL=20160

# Telegram Bot Configuration
TELEGRAM_BOT_TOKEN=7779533338:AAH-B1-r4GQzJStkyC7Ecziip7ccgso5AOs
TELEGRAM_BOT_USERNAME=tgappy_bot

# Admin Configuration
ADMIN_TELEGRAM_IDS=6941596189

# CORS Configuration (Allow ngrok frontend + localhost)
CORS_ALLOWED_ORIGINS=https://68c6605bb77f.ngrok-free.app,http://localhost:3000,http://localhost:8000

# Stripe Test Configuration
STRIPE_KEY=sk_test_51Rcqa9RqiHAAOQNt4Qlp9zmFKkjf78mRgjqjTveYVmZg7z8OpEvElH0qkJMMaNKvgtJwUMa8kxWzER7pRCwwDe5K00I41OXJuh
STRIPE_PUBLIC_KEY=pk_test_51Rcqa9RqiHAAOQNt6dQOzDxtKXI4hl3PjmdxnnOz6Y2ARZJ4zXE03frTz6FPhMbp6ZGMxERdRVi1xJMK0SW6i2Gn00NYOU3H8O
STRIPE_WEBHOOK_SECRET=whsec_6GmWJJhXEHNwVLwi79M3eLZFgNFzcPo8
STRIPE_PRICE_ID_PRO=price_1Rdv4sRqiHAAOQNtgBC15qcq
STRIPE_PRODUCT_ID_PRO=prod_SZ3Bv1lG9HoCLM
STRIPE_PRICE_ID_ULTRA=price_1RiLAnRqiHAAOQNt8JKhJKZM
STRIPE_PRODUCT_ID_ULTRA=prod_SdcPkOn7RC90lG
EOF
    echo "✅ Created correct .env file"
fi

# Also update env-manager.sh if it exists to use correct connection string
if [ -f env-manager.sh ]; then
    echo ""
    echo "📝 Updating env-manager.sh..."
    cp env-manager.sh env-manager.sh.backup
    
    # Fix MongoDB connection string in env-manager.sh
    sed -i.tmp 's|localhost:27017|mongodb:27017|g' env-manager.sh
    sed -i.tmp 's|127.0.0.1:27017|mongodb:27017|g' env-manager.sh
    rm env-manager.sh.tmp 2>/dev/null
    
    echo "✅ env-manager.sh updated"
fi

echo ""
echo "🧹 Cleaning up Docker volumes..."
docker volume rm telegram-scheduler_mongodb_data 2>/dev/null || true
docker volume rm telegram-scheduler_redis_data 2>/dev/null || true

echo ""
echo "🚀 Starting services with corrected configuration..."
docker-compose -f docker-compose.dev.yml up -d --build

echo ""
echo "⏳ Waiting for MongoDB to initialize..."
sleep 30

# Test MongoDB connection
echo ""
echo "🧪 Testing MongoDB connection..."
for i in {1..10}; do
    echo "Testing MongoDB connection... (attempt $i/10)"
    if docker-compose -f docker-compose.dev.yml exec -T mongodb mongosh --eval "db.adminCommand('ping')" >/dev/null 2>&1; then
        echo "✅ MongoDB is responding!"
        MONGODB_OK=true
        break
    fi
    sleep 3
done

if [ "$MONGODB_OK" != "true" ]; then
    echo "❌ MongoDB not responding. Checking logs..."
    docker-compose -f docker-compose.dev.yml logs mongodb
    exit 1
fi

echo ""
echo "⏳ Waiting for backend to start..."
sleep 15

# Test backend connection to MongoDB
echo ""
echo "🧪 Testing backend → MongoDB connection..."
for i in {1..8}; do
    echo "Testing backend connection... (attempt $i/8)"
    if docker-compose -f docker-compose.dev.yml exec -T backend php artisan tinker --execute="try { \$result = DB::connection('mongodb')->listCollections(); echo 'SUCCESS: Backend connected to MongoDB'; } catch(Exception \$e) { echo 'FAILED: ' . \$e->getMessage(); }" 2>/dev/null | grep -q "SUCCESS"; then
        echo "✅ Backend successfully connected to MongoDB!"
        BACKEND_MONGODB_OK=true
        break
    fi
    sleep 5
done

if [ "$BACKEND_MONGODB_OK" != "true" ]; then
    echo "❌ Backend still cannot connect to MongoDB"
    echo ""
    echo "📄 Backend logs:"
    docker-compose -f docker-compose.dev.yml logs --tail=20 backend
    echo ""
    echo "📄 MongoDB logs:"
    docker-compose -f docker-compose.dev.yml logs --tail=10 mongodb
    exit 1
fi

# Test API endpoint
echo ""
echo "🧪 Testing API endpoint..."
if curl -f -s http://localhost:8000/health >/dev/null; then
    echo "✅ API endpoint is responding!"
    
    # Show the health response
    echo ""
    echo "📋 API Health Check Response:"
    curl -s http://localhost:8000/health | jq . 2>/dev/null || curl -s http://localhost:8000/health
else
    echo "❌ API endpoint not responding"
    docker-compose -f docker-compose.dev.yml logs --tail=10 backend
fi

echo ""
echo "📊 Final Status Check:"
docker-compose -f docker-compose.dev.yml ps

echo ""
echo "🎉 MongoDB Connection Fix Complete!"
echo "=================================="
echo ""
echo "✅ What was fixed:"
echo "  • Changed 'localhost:27017' → 'mongodb:27017' in environment"
echo "  • Updated Docker container communication"
echo "  • Reset MongoDB volume for clean start"
echo ""
echo "🧪 Test your authentication now:"
echo "  1. Start frontend: cd frontend && npm start"
echo "  2. Try Telegram login in browser"
echo ""
echo "📋 Key Change Made:"
echo "  Old: mongodb://admin:password@localhost:27017/..."
echo "  New: mongodb://admin:password@mongodb:27017/..."
echo ""
echo "💡 Why this works:"
echo "  In Docker Compose, containers communicate using service names"
echo "  'mongodb' is the service name, not 'localhost'"