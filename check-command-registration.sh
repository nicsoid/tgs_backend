#!/bin/bash
# check-command-registration.sh - Verify command registration

echo "🔍 CHECKING COMMAND REGISTRATION ISSUES"
echo "======================================="

echo ""
echo "1. VERIFY COMMAND FILE EXISTS"
echo "============================="
echo "Checking if ProcessScheduledPosts.php exists:"
if docker-compose exec backend test -f "app/Console/Commands/ProcessScheduledPosts.php"; then
    echo "✅ ProcessScheduledPosts.php exists"
    
    echo ""
    echo "Command file details:"
    docker-compose exec backend ls -la "app/Console/Commands/ProcessScheduledPosts.php"
    
    echo ""
    echo "Command signature (first 10 lines):"
    docker-compose exec backend head -10 "app/Console/Commands/ProcessScheduledPosts.php"
else
    echo "❌ ProcessScheduledPosts.php NOT FOUND!"
    echo ""
    echo "Available command files:"
    docker-compose exec backend ls -la "app/Console/Commands/"
fi

echo ""
echo "2. CHECK ARTISAN COMMAND REGISTRATION"
echo "===================================="
echo "All available artisan commands:"
docker-compose exec backend php artisan list | grep -E "(posts|schedule)"

echo ""
echo "3. CHECK NAMESPACE AND CLASS LOADING"
echo "===================================="
echo "Testing if the command class can be loaded:"
docker-compose exec backend php artisan tinker --execute="
try {
    \$command = new App\Console\Commands\ProcessScheduledPosts();
    echo 'Command class loaded successfully: ✅';
    echo 'Command signature: ' . \$command->getName();
} catch (Exception \$e) {
    echo 'Command class loading failed: ❌';
    echo 'Error: ' . \$e->getMessage();
}
"

echo ""
echo "4. CHECK COMMAND REGISTRATION IN KERNEL"
echo "======================================="
echo "Verifying commands are loaded in Kernel:"
docker-compose exec backend php artisan tinker --execute="
try {
    \$kernel = app(\Illuminate\Contracts\Console\Kernel::class);
    \$commands = \$kernel->all();
    
    \$postsCommands = array_filter(\$commands, function(\$command) {
        return strpos(\$command->getName(), 'posts:') === 0;
    });
    
    echo 'Posts commands found: ' . count(\$postsCommands);
    foreach (\$postsCommands as \$command) {
        echo '- ' . \$command->getName() . ' (' . get_class(\$command) . ')';
    }
} catch (Exception \$e) {
    echo 'Error checking commands: ' . \$e->getMessage();
}
"

echo ""
echo "5. CHECK SCHEDULE REGISTRATION"
echo "============================="
echo "Checking if schedule is properly registered:"
docker-compose exec backend php artisan schedule:list

echo ""
echo "6. MANUAL COMMAND EXECUTION TEST"
echo "==============================="
echo "Testing command execution:"
docker-compose exec backend php artisan posts:process-scheduled --help

echo ""
echo "7. CHECK AUTOLOADING"
echo "==================="
echo "Checking if composer autoload is working:"
docker-compose exec backend composer dump-autoload

echo ""
echo "8. CREATE SIMPLE TEST COMMAND"
echo "============================="
echo "Creating a simple test command to verify registration works:"
docker-compose exec backend php artisan make:command TestCommand --command=test:simple

echo ""
echo "Testing if the new command appears:"
docker-compose exec backend php artisan list | grep "test:"

echo ""
echo "9. COMPARE WITH WORKING COMMANDS"
echo "==============================="
echo "Checking other commands in the same directory:"
docker-compose exec backend php artisan list | grep -E "(admin:|currencies:|scheduler:|groups:)"

echo ""
echo "🎯 DIAGNOSIS RESULTS"
echo "==================="
echo ""
echo "If posts:process-scheduled does NOT appear in 'php artisan list':"
echo "❌ Command registration is broken"
echo ""
echo "Possible causes:"
echo "1. Command file doesn't exist or has syntax errors"
echo "2. Class name doesn't match filename"
echo "3. Namespace is incorrect"
echo "4. Autoloading issue"
echo "5. Command signature property is missing or invalid"
echo ""
echo "✅ FIXES TO TRY:"
echo ""
echo "# Clear all caches and reload:"
echo "docker-compose exec backend composer dump-autoload"
echo "docker-compose exec backend php artisan config:clear"
echo "docker-compose exec backend php artisan cache:clear"
echo ""
echo "# Restart containers:"
echo "docker-compose restart backend scheduler"
echo ""
echo "# If command still missing, recreate it:"
echo "docker-compose exec backend php artisan make:command ProcessScheduledPosts --command=posts:process-scheduled"