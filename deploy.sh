#!/bin/bash
# deploy.sh - Production deployment script

set -e  # Exit on any error

echo "🚀 Starting production deployment..."

# Check requirements
command -v docker >/dev/null 2>&1 || { echo "Docker is required but not installed. Aborting." >&2; exit 1; }
command -v docker-compose >/dev/null 2>&1 || { echo "Docker Compose is required but not installed. Aborting." >&2; exit 1; }

# Load environment variables
if [ -f .env.production ]; then
    export $(cat .env.production | grep -v '#' | xargs)
else
    echo "❌ .env.production file not found!"
    exit 1
fi

# Validate required environment variables
required_vars=("MONGO_PASSWORD" "TELEGRAM_BOT_TOKEN" "APP_KEY" "JWT_SECRET")
for var in "${required_vars[@]}"; do
    if [ -z "${!var}" ]; then
        echo "❌ Required environment variable $var is not set!"
        exit 1
    fi
done

echo "✅ Environment variables validated"

# Build and deploy
echo "📦 Building containers..."
docker-compose -f docker-compose.prod.yml build --no-cache

echo "🚀 Starting services..."
docker-compose -f docker-compose.prod.yml up -d

# Wait for services to be ready
echo "⏳ Waiting for services to start..."
sleep 30

# Run migrations and setup
echo "🗄️  Setting up database..."
docker-compose -f docker-compose.prod.yml exec -T backend php artisan migrate --force
docker-compose -f docker-compose.prod.yml exec -T backend php artisan db:setup-indexes

# Verify deployment
echo "✅ Verifying deployment..."
docker-compose -f docker-compose.prod.yml exec -T backend php artisan messages:process-scheduled --dry-run

echo "🎉 Production deployment completed!"
echo ""
echo "📊 Monitor with:"
echo "  docker-compose -f docker-compose.prod.yml logs -f"
echo "  docker-compose -f docker-compose.prod.yml exec backend php artisan queue:monitor"
echo ""
echo "📈 Scale workers if needed:"
echo "  docker-compose -f docker-compose.prod.yml up -d --scale queue-worker-medium=5"
