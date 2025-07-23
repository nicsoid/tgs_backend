#!/bin/bash
# backend/docker/entrypoint.sh - Fixed Laravel server startup
set -e

# Function to wait for service
wait_for_service() {
    host="$1"
    port="$2"
    service_name="$3"
    
    echo "Waiting for $service_name at $host:$port..."
    timeout=60
    count=0
    
    while ! nc -z "$host" "$port" 2>/dev/null; do
        sleep 1
        count=$((count + 1))
        if [ $count -gt $timeout ]; then
            echo "❌ Timeout waiting for $service_name"
            exit 1
        fi
    done
    echo "✅ $service_name is ready!"
}

# Install netcat if not present
if ! command -v nc &> /dev/null; then
    echo "Installing netcat..."
    apt-get update && apt-get install -y netcat-openbsd
fi

# Switch based on container role
case "${CONTAINER_ROLE:-app}" in
    "app")
        echo "🚀 Starting application server..."
        
        # Wait for dependencies
        wait_for_service mongodb 27017 "MongoDB"
        wait_for_service redis 6379 "Redis"
        
        # Ensure storage directories exist with proper permissions
        echo "📁 Setting up storage directories..."
        mkdir -p /var/www/html/storage/logs
        mkdir -p /var/www/html/storage/framework/cache
        mkdir -p /var/www/html/storage/framework/sessions
        mkdir -p /var/www/html/storage/framework/views
        mkdir -p /var/www/html/storage/app/public/media
        mkdir -p /var/www/html/bootstrap/cache
        
        # Set proper permissions (only if we own the directories)
        if [ -w "/var/www/html/storage" ]; then
            chown -R www-data:www-data /var/www/html/storage 2>/dev/null || true
            chmod -R 775 /var/www/html/storage 2>/dev/null || true
        fi
        
        if [ -w "/var/www/html/bootstrap/cache" ]; then
            chown -R www-data:www-data /var/www/html/bootstrap/cache 2>/dev/null || true
            chmod -R 775 /var/www/html/bootstrap/cache 2>/dev/null || true
        fi
        
        # Generate app key if not set
        if [ -z "$APP_KEY" ] || [ "$APP_KEY" = "" ]; then
            echo "🔐 Generating application key..."
            php artisan key:generate --force
        fi
        
        # Clear Laravel caches
        echo "🧹 Clearing caches..."
        php artisan config:clear || true
        php artisan route:clear || true
        php artisan view:clear || true
        php artisan cache:clear || true
        
        # Run migrations
        echo "🗄️ Running database migrations..."
        php artisan migrate --force || echo "⚠️ Migration failed or nothing to migrate"
        
        # Setup database indexes (with fallback)
        echo "📊 Setting up database indexes..."
        php artisan db:setup-indexes || echo "⚠️ Indexes setup failed or already exist"
        
        # Handle storage link (check if it already exists)
        echo "🔗 Checking storage link..."
        if [ -L "/var/www/html/public/storage" ]; then
            echo "ℹ️ Storage link already exists (from local development)"
        elif [ -d "/var/www/html/public/storage" ]; then
            echo "ℹ️ Storage directory already exists (from local development)"
        else
            echo "Creating new storage link..."
            php artisan storage:link || echo "⚠️ Failed to create storage link"
        fi
        
        # Run database seeders (only if needed)
        echo "🌱 Running database seeders..."
        php artisan db:seed --force || echo "ℹ️ Seeders already run or failed"
        
        echo "✅ Application setup completed!"
        echo "🚀 Starting Laravel server..."
        
        # FIXED: Explicitly start Laravel server instead of using exec "$@"
        exec php artisan serve --host=0.0.0.0 --port=8000 --verbose
        ;;
        
    "queue")
        echo "⚡ Starting queue worker..."
        wait_for_service mongodb 27017 "MongoDB"
        wait_for_service redis 6379 "Redis"
        echo "✅ Queue worker ready, starting..."
        exec php artisan queue:work redis --sleep=1 --tries=3 --max-time=3600 --memory=512
        ;;
        
    "scheduler")
        echo "⏰ Starting scheduler..."
        wait_for_service mongodb 27017 "MongoDB" 
        wait_for_service redis 6379 "Redis"
        echo "✅ Scheduler ready, starting..."
        exec sh -c "
            while true; do
                php artisan schedule:run --verbose --no-interaction
                sleep 60
            done
        "
        ;;
        
    *)
        echo "❓ Unknown container role: $CONTAINER_ROLE"
        echo "Starting with default command..."
        exec "$@"
        ;;
esac
