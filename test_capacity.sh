#!/bin/bash
# test_capacity.sh - Test system capacity

echo "🧪 CAPACITY TESTING TOOL"
echo "========================"

test_message_volume() {
    local messages_per_minute=$1
    local duration_minutes=$2
    
    echo "Testing $messages_per_minute messages/minute for $duration_minutes minutes..."
    
    for ((i=1; i<=$duration_minutes; i++)); do
        echo "Minute $i: Dispatching $messages_per_minute messages..."
        
        # Simulate message dispatching
        docker-compose -f docker-compose.prod.yml exec -T backend php artisan tinker --execute="
            for (\$j = 0; \$j < $messages_per_minute; \$j++) {
                \App\Jobs\SendTelegramMessage::dispatch('test', 'test', 'test', ['text' => 'Test message'], now());
            }
            echo 'Dispatched $messages_per_minute messages';
        "
        
        sleep 60
    done
}

# Test scenarios
echo "Choose test scenario:"
echo "1. Light load (10 messages/minute for 10 minutes)"
echo "2. Medium load (100 messages/minute for 10 minutes)" 
echo "3. Heavy load (500 messages/minute for 5 minutes)"
echo "4. Peak load (1000 messages/minute for 2 minutes)"

read -p "Enter choice (1-4): " choice

case $choice in
    1) test_message_volume 10 10 ;;
    2) test_message_volume 100 10 ;;
    3) test_message_volume 500 5 ;;
    4) test_message_volume 1000 2 ;;
    *) echo "Invalid choice" ;;
esac
