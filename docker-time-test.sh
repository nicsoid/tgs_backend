#!/bin/bash
# docker-time-test.sh - Check Docker time and test message sending

echo "🕐 Docker Time Check & Message Testing"
echo "====================================="

case "$1" in
    "time")
        echo "🕐 Checking time in Docker containers..."
        echo ""
        
        echo "📅 Host machine time:"
        date
        echo ""
        
        echo "📅 Backend container time:"
        docker-compose -f docker-compose.dev.yml exec backend date || docker-compose exec backend date
        echo ""
        
        echo "📅 MongoDB container time:"
        docker-compose -f docker-compose.dev.yml exec mongodb date || docker-compose exec mongodb date
        echo ""
        
        echo "🌍 Backend container timezone info:"
        docker-compose -f docker-compose.dev.yml exec backend cat /etc/timezone 2>/dev/null || echo "Timezone file not found"
        docker-compose -f docker-compose.dev.yml exec backend ls -la /etc/localtime || echo "Localtime link not found"
        echo ""
        
        echo "⏰ PHP timezone in backend:"
        docker-compose -f docker-compose.dev.yml exec backend php -r "echo 'PHP Timezone: ' . date_default_timezone_get() . PHP_EOL;"
        docker-compose -f docker-compose.dev.yml exec backend php -r "echo 'Current PHP time: ' . date('Y-m-d H:i:s T') . PHP_EOL;"
        echo ""
        
        echo "🕐 Laravel app timezone:"
        docker-compose -f docker-compose.dev.yml exec backend php artisan tinker --execute="echo 'Laravel timezone: ' . config('app.timezone'); echo PHP_EOL; echo 'Laravel time: ' . now()->format('Y-m-d H:i:s T'); echo PHP_EOL;"
        ;;
        
    "test-message")
        echo "📤 Testing Message Sending"
        echo "========================="
        
        # Get the bot info first
        echo "🤖 Bot Information:"
        BOT_TOKEN="7779533338:AAH-B1-r4GQzJStkyC7Ecziip7ccgso5AOs"
        curl -s "https://api.telegram.org/bot$BOT_TOKEN/getMe" | jq . 2>/dev/null || curl -s "https://api.telegram.org/bot$BOT_TOKEN/getMe"
        echo ""
        
        echo "🔗 Current webhook:"
        curl -s "https://api.telegram.org/bot$BOT_TOKEN/getWebhookInfo" | jq . 2>/dev/null || curl -s "https://api.telegram.org/bot$BOT_TOKEN/getWebhookInfo"
        echo ""
        
        # Test sending a message to admin
        ADMIN_ID="6941596189"
        echo "📨 Sending test message to admin ($ADMIN_ID)..."
        
        response=$(curl -s -X POST "https://api.telegram.org/bot$BOT_TOKEN/sendMessage" \
             -H "Content-Type: application/json" \
             -d "{
                \"chat_id\": \"$ADMIN_ID\",
                \"text\": \"🧪 Test message from Telegram Scheduler\\n\\nTime: $(date)\\n\\nIf you see this, the bot is working!\",
                \"parse_mode\": \"HTML\"
             }")
        
        echo "Response:"
        echo "$response" | jq . 2>/dev/null || echo "$response"
        
        if echo "$response" | grep -q '"ok":true'; then
            echo ""
            echo "✅ Test message sent successfully!"
            echo "Check your Telegram for the message."
        else
            echo ""
            echo "❌ Failed to send test message"
            echo "Check the response above for error details"
        fi
        ;;
        
    "test-schedule")
        echo "📅 Testing Message Scheduling"
        echo "============================"
        
        echo "This will test scheduling a message for 2 minutes from now..."
        echo ""
        
        # Calculate time 2 minutes from now
        future_time=$(date -d "+2 minutes" "+%Y-%m-%d %H:%M:%S")
        echo "📅 Scheduling test message for: $future_time"
        echo ""
        
        # Get user token (you'll need to provide this)
        read -p "🔑 Enter your JWT token (from browser localStorage or login): " user_token
        
        if [ -z "$user_token" ]; then
            echo "❌ Token required for scheduling test"
            exit 1
        fi
        
        # Test API call to schedule a message
        echo "📤 Creating scheduled post..."
        
        response=$(curl -s -X POST "http://localhost:8000/api/scheduled-posts" \
             -H "Content-Type: application/json" \
             -H "Authorization: Bearer $user_token" \
             -d "{
                \"group_ids\": [\"test_group\"],
                \"content\": {
                    \"text\": \"🧪 Scheduled test message\\n\\nScheduled at: $(date)\\nExpected delivery: $future_time\"
                },
                \"schedule_times\": [\"$future_time\"],
                \"user_timezone\": \"UTC\"
             }")
        
        echo "Response:"
        echo "$response" | jq . 2>/dev/null || echo "$response"
        
        if echo "$response" | grep -q '"id"'; then
            echo ""
            echo "✅ Test message scheduled!"
            echo "⏰ Check in 2 minutes to see if it was sent"
        else
            echo ""
            echo "❌ Failed to schedule test message"
        fi
        ;;
        
    "check-queue")
        echo "🔄 Checking Queue Status"
        echo "======================="
        
        echo "📊 Queue workers status:"
        docker-compose -f docker-compose.dev.yml ps | grep queue-worker || docker-compose ps | grep queue-worker
        echo ""
        
        echo "📋 Queue jobs in Redis:"
        docker-compose -f docker-compose.dev.yml exec redis redis-cli LLEN "queues:default" || docker-compose exec redis redis-cli LLEN "queues:default"
        echo ""
        
        echo "📄 Recent queue worker logs:"
        docker-compose -f docker-compose.dev.yml logs --tail=20 queue-worker || docker-compose logs --tail=20 queue-worker
        echo ""
        
        echo "🕐 Scheduler status:"
        docker-compose -f docker-compose.dev.yml logs --tail=10 scheduler || docker-compose logs --tail=10 scheduler
        ;;
        
    "database-check")
        echo "🗄️ Database Content Check"
        echo "========================"
        
        echo "📊 Checking database collections:"
        docker-compose -f docker-compose.dev.yml exec backend php artisan tinker --execute="
            try {
                \$collections = DB::connection('mongodb')->listCollections();
                echo 'Available collections:' . PHP_EOL;
                foreach(\$collections as \$collection) {
                    \$name = \$collection->getName();
                    \$count = DB::connection('mongodb')->collection(\$name)->count();
                    echo '  - ' . \$name . ': ' . \$count . ' documents' . PHP_EOL;
                }
            } catch(Exception \$e) {
                echo 'Error: ' . \$e->getMessage() . PHP_EOL;
            }
        "
        echo ""
        
        echo "👤 Users in database:"
        docker-compose -f docker-compose.dev.yml exec backend php artisan tinker --execute="
            try {
                \$users = App\Models\User::all();
                echo 'Total users: ' . \$users->count() . PHP_EOL;
                foreach(\$users as \$user) {
                    echo '  - ' . \$user->first_name . ' ' . \$user->last_name . ' (@' . \$user->username . ')' . PHP_EOL;
                }
            } catch(Exception \$e) {
                echo 'Error: ' . \$e->getMessage() . PHP_EOL;
            }
        "
        echo ""
        
        echo "📅 Scheduled posts:"
        docker-compose -f docker-compose.dev.yml exec backend php artisan tinker --execute="
            try {
                \$posts = App\Models\ScheduledPost::all();
                echo 'Total scheduled posts: ' . \$posts->count() . PHP_EOL;
                foreach(\$posts as \$post) {
                    echo '  - Status: ' . \$post->status . ', Times: ' . count(\$post->schedule_times) . ', Groups: ' . count(\$post->group_ids) . PHP_EOL;
                }
            } catch(Exception \$e) {
                echo 'Error: ' . \$e->getMessage() . PHP_EOL;
            }
        "
        ;;
        
    "logs")
        service="${2:-backend}"
        echo "📄 Viewing logs for: $service"
        echo "=========================="
        
        docker-compose -f docker-compose.dev.yml logs -f --tail=50 $service || docker-compose logs -f --tail=50 $service
        ;;
        
    *)
        echo "🛠️ Docker Time Check & Message Testing"
        echo "======================================"
        echo ""
        echo "Usage: $0 [command]"
        echo ""
        echo "⏰ Time Commands:"
        echo "  time                - Check time in all containers"
        echo ""
        echo "📤 Message Testing:"
        echo "  test-message        - Send test message via bot"
        echo "  test-schedule       - Test scheduling a message"
        echo ""
        echo "🔧 System Checks:"
        echo "  check-queue         - Check queue workers and jobs"
        echo "  database-check      - Check database content"
        echo "  logs [service]      - View service logs"
        echo ""
        echo "📋 Quick Commands:"
        echo "  $0 time             # Check if container time is correct"
        echo "  $0 test-message     # Send test message to admin"
        echo "  $0 check-queue      # See if queue workers are processing"
        echo "  $0 database-check   # Check what's in the database"
        echo ""
        echo "🕐 Common Time Issues:"
        echo "  • Docker containers often use UTC timezone"
        echo "  • Laravel timezone set in config/app.php"
        echo "  • User timezone handled in frontend/API"
        echo "  • Queue jobs run in container time"
        echo ""
        echo "📨 Testing Message Flow:"
        echo "  1. Check bot can send messages: $0 test-message"
        echo "  2. Create scheduled post via frontend"
        echo "  3. Check queue processing: $0 check-queue"
        echo "  4. Monitor logs: $0 logs queue-worker"
        ;;
esac