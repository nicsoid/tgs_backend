# docker/entrypoint.sh
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

# Wait for dependencies
if [ "$CONTAINER_ROLE" != "app" ]; then
    wait_for_service mongodb 27017 "MongoDB"
    wait_for_service redis 6379 "Redis"
fi

# Switch based on container role
case "${CONTAINER_ROLE:-app}" in
    "app")
        echo "Starting application server..."
        
        # Wait for dependencies
        wait_for_service mongodb 27017 "MongoDB"
        wait_for_service redis 6379 "Redis"
        
        # Run Laravel setup
        php artisan config:cache
        php artisan route:cache
        php artisan view:cache
        
        # Run migrations
        php artisan migrate --force
        
        # Setup indexes
        php artisan db:setup-indexes || echo "Indexes may already exist"
        
        # Start application
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