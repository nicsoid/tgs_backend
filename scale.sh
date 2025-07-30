#!/bin/bash
# scale.sh - Dynamic scaling based on queue size

get_queue_size() {
    docker-compose -f docker-compose.prod.yml exec -T redis redis-cli llen queues:telegram-high
}

scale_workers() {
    local queue_size=$1
    local current_workers=$2
    local target_workers

    if [ "$queue_size" -gt 500 ]; then
        target_workers=6
    elif [ "$queue_size" -gt 200 ]; then
        target_workers=4
    elif [ "$queue_size" -gt 50 ]; then
        target_workers=2
    else
        target_workers=1
    fi

    if [ "$target_workers" -ne "$current_workers" ]; then
        echo "📈 Scaling workers from $current_workers to $target_workers"
        docker-compose -f docker-compose.prod.yml up -d --scale queue-worker-medium=$target_workers
    fi
}

# Auto-scaling loop
echo "🔄 Starting auto-scaling monitor..."
while true; do
    queue_size=$(get_queue_size)
    current_workers=$(docker-compose -f docker-compose.prod.yml ps queue-worker-medium | grep -c "Up")
    
    echo "[$(date)] Queue size: $queue_size, Workers: $current_workers"
    
    scale_workers $queue_size $current_workers
    
    sleep 300  # Check every 5 minutes
done
