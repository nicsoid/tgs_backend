#!/bin/bash
# manual-scheduler.sh - Bypass Laravel scheduler completely

echo "🕐 Starting Manual Scheduler Replacement"
echo "========================================"

while true; do
    echo "[$(date)] Running posts:process-scheduled manually..."
    
    # Run the command directly every minute
    docker-compose exec -T backend php artisan posts:process-scheduled
    
    echo "[$(date)] Completed. Waiting 60 seconds..."
    sleep 60
done
