#!/bin/bash
# Docker Setup Fix Script

echo "🐳 Fixing Docker setup for Telegram Scheduler..."

# 1. Create missing directory structure
echo "📁 Creating directory structure..."
mkdir -p backend/docker/php
mkdir -p backend/docker/supervisor
mkdir -p docker/nginx/conf.d
mkdir -p docker/mongodb
mkdir -p docker/redis

# 2. Create backend/docker/entrypoint.sh
echo "📝 Creating entrypoint script..."
cat > backend/docker/entrypoint.sh << 'EOF'
#!/bin/bash
set -e

# Function to wait for service
wait_for_service() {
    host="$1"
    port="$2"
    service_name="$3"
    
    echo "Waiting for $service_name at $host:$port..."
    while ! nc -z "$host" "$port"; do
        sleep 1
    done
    echo "$service_name is ready!"
}

# Install netcat if not present
if ! command -v nc &> /dev/null; then
    apt-get update && apt-get install -y netcat-openbsd
fi

# Switch based on container role
case "${CONTAINER_ROLE:-app}" in
    "app")
        echo "Starting application server..."
        
        # Wait for dependencies
        wait_for_service mongodb 27017 "MongoDB"
        wait_for_service redis 6379 "Redis"
        
        # Ensure storage directories exist
        mkdir -p /var/www/html/storage/logs
        mkdir -p /var/www/html/storage/framework/cache
        mkdir -p /var/www/html/storage/framework/sessions
        mkdir -p /var/www/html/storage/framework/views
        mkdir -p /var/www/html/storage/app/public/media
        mkdir -p /var/www/html/bootstrap/cache
        
        # Set permissions
        chown -R www-data:www-data /var/www/html/storage
        chown -R www-data:www-data /var/www/html/bootstrap/cache
        chmod -R 775 /var/www/html/storage
        chmod -R 775 /var/www/html/bootstrap/cache
        
        # Generate app key if not set
        if [ -z "$APP_KEY" ] || [ "$APP_KEY" = "" ]; then
            echo "Generating application key..."
            php artisan key:generate --force
        fi
        
        # Clear Laravel caches
        php artisan config:clear
        php artisan route:clear
        php artisan view:clear
        php artisan cache:clear
        
        # Run migrations
        echo "Running database migrations..."
        php artisan migrate --force
        
        # Setup database indexes
        echo "Setting up database indexes..."
        php artisan db:setup-indexes || echo "Indexes setup failed or already exist"
        
        # Create symbolic link for storage
        php artisan storage:link || echo "Storage link already exists"
        
        echo "Starting application server..."
        exec "$@"
        ;;
        
    "queue")
        echo "Starting queue worker..."
        wait_for_service mongodb 27017 "MongoDB"
        wait_for_service redis 6379 "Redis"
        exec "$@"
        ;;
        
    "scheduler")
        echo "Starting scheduler..."
        wait_for_service mongodb 27017 "MongoDB" 
        wait_for_service redis 6379 "Redis"
        exec "$@"
        ;;
        
    *)
        echo "Unknown container role: $CONTAINER_ROLE"
        exec "$@"
        ;;
esac
EOF

chmod +x backend/docker/entrypoint.sh

# 3. Create backend/docker/php/php.ini
echo "⚙️  Creating PHP configuration..."
cat > backend/docker/php/php.ini << 'EOF'
memory_limit = 512M
max_execution_time = 300
upload_max_filesize = 50M
post_max_size = 50M
max_file_uploads = 20

; OPcache settings
opcache.enable = 1
opcache.memory_consumption = 256
opcache.interned_strings_buffer = 8
opcache.max_accelerated_files = 20000
opcache.revalidate_freq = 2
opcache.fast_shutdown = 1

; Redis settings
session.save_handler = redis
session.save_path = "tcp://redis:6379"

; Error reporting
log_errors = On
error_log = /var/log/php_errors.log
EOF

# 4. Create backend/docker/supervisor/supervisord.conf
echo "👥 Creating Supervisor configuration..."
cat > backend/docker/supervisor/supervisord.conf << 'EOF'
[supervisord]
nodaemon=true
user=root
logfile=/var/log/supervisor/supervisord.log
pidfile=/var/run/supervisord.pid

[program:php-fpm]
command=php-fpm
autostart=true
autorestart=true
stderr_logfile=/var/log/supervisor/php-fpm.err.log
stdout_logfile=/var/log/supervisor/php-fpm.out.log

[program:queue-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/html/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600 --memory=512
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/supervisor/worker.log
stopwaitsecs=3600
EOF

# 5. Create Nginx configuration
echo "🌐 Creating Nginx configuration..."
cat > docker/nginx/conf.d/default.conf << 'EOF'
upstream backend {
    server backend:8000;
}

upstream frontend {
    server frontend:80;
}

# Rate limiting
limit_req_zone $binary_remote_addr