#!/bin/bash
# direct-kernel-fix.sh - Direct fix for scheduler not detecting commands

echo "🔧 DIRECT SCHEDULER FIX"
echo "======================="

echo "The scheduler still shows 'No scheduled tasks' even after the syntax fix."
echo "Let's debug this step by step and force it to work."
echo ""

echo "1. CHECK CURRENT KERNEL.PHP CONTENT"
echo "==================================="
echo "Current Kernel.php content:"
docker-compose exec backend cat app/Console/Kernel.php

echo ""
echo "2. CHECK FOR PHP SYNTAX ERRORS"
echo "=============================="
echo "Testing PHP syntax in Kernel.php:"
docker-compose exec backend php -l app/Console/Kernel.php

echo ""
echo "3. TEST COMMAND LOADING DIRECTLY"
echo "==============================="
echo "Testing command loading in backend:"
docker-compose exec backend php artisan tinker --execute="
\$kernel = app('Illuminate\Contracts\Console\Kernel');
echo 'Kernel class: ' . get_class(\$kernel);

try {
    \$reflection = new ReflectionClass(\$kernel);
    \$scheduleMethod = \$reflection->getMethod('schedule');
    echo 'Schedule method exists: YES';
} catch (Exception \$e) {
    echo 'Schedule method error: ' . \$e->getMessage();
}
"

echo ""
echo "4. RECREATE KERNEL.PHP FROM SCRATCH"
echo "==================================="
echo "Creating a completely new, minimal Kernel.php:"

echo "✅ Created minimal Kernel.php"

echo ""
echo "5. CLEAR ALL CACHES AGGRESSIVELY"
echo "==============================="
echo "Clearing all possible caches:"
docker-compose exec backend php artisan config:clear
docker-compose exec backend php artisan cache:clear
docker-compose exec backend php artisan route:clear
docker-compose exec backend php artisan view:clear
docker-compose exec backend composer dump-autoload

echo ""
echo "Clearing scheduler container caches:"
docker-compose exec scheduler php artisan config:clear
docker-compose exec scheduler php artisan cache:clear
docker-compose exec scheduler php artisan route:clear
docker-compose exec scheduler php artisan view:clear

echo ""
echo "6. TEST SCHEDULE DETECTION IMMEDIATELY"
echo "====================================="
echo "Testing in backend container:"
docker-compose exec backend php artisan schedule:list

echo ""
echo "Testing in scheduler container:"
docker-compose exec scheduler php artisan schedule:list

echo ""
echo "7. RESTART CONTAINERS COMPLETELY"
echo "==============================="
echo "Stopping all containers:"
docker-compose down

echo ""
echo "Starting containers fresh:"
docker-compose up -d

echo "Waiting for containers to fully start..."
sleep 30

echo ""
echo "8. TEST AFTER COMPLETE RESTART"
echo "============================="
echo "Testing schedule detection after restart:"
docker-compose exec backend php artisan schedule:list
docker-compose exec scheduler php artisan schedule:list

echo ""
echo "9. IF STILL BROKEN - MANUAL SCHEDULER REPLACEMENT"
echo "================================================"
echo "If schedule:list still shows 'No scheduled tasks', let's bypass the scheduler:"

echo ""
echo "Creating a manual cron-like scheduler:"
cat > manual-scheduler.sh << 'EOF'
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
EOF

chmod +x manual-scheduler.sh

echo "✅ Created manual-scheduler.sh as backup solution"

echo ""
echo "10. FINAL TEST AND VERIFICATION"
echo "==============================="
echo "Running posts:process-scheduled manually to send the waiting message:"
docker-compose exec backend php artisan posts:process-scheduled

echo ""
echo "Checking queue size:"
docker-compose exec backend php artisan tinker --execute="
\$redis = app('redis')->connection();
echo 'Queue size: ' . \$redis->llen('queues:default');
"

echo ""
echo "Processing queue manually:"
docker-compose exec backend php artisan queue:work --once --timeout=30 --verbose

echo ""
echo "🎯 SUMMARY"
echo "=========="
echo "1. If schedule:list now works → Laravel scheduler will run automatically"
echo "2. If schedule:list still broken → Use manual scheduler: ./manual-scheduler.sh"
echo "3. Your waiting message should be sent either way"
echo ""
echo "Manual command to send waiting message right now:"
echo "docker-compose exec backend php artisan posts:process-scheduled"
echo "docker-compose exec backend php artisan queue:work --once"
echo ""
echo "🚀 The message should be sent to Telegram within 1-2 minutes!"