<?php
// app/Console/Commands/AdminSetup.php - Setup command for initial admin configuration

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class AdminSetup extends Command
{
    protected $signature = 'admin:setup';
    protected $description = 'Set up admin functionality';

    public function handle()
    {
        $this->info('🔧 Setting up Admin functionality...');
        $this->info('===================================');

        // Check if admin middleware is registered
        $kernelPath = app_path('Http/Kernel.php');
        $kernelContent = File::get($kernelPath);
        
        if (!str_contains($kernelContent, "'admin' =>")) {
            $this->warn('⚠️  Admin middleware not found in Kernel.php');
            $this->info('Please add this line to the $middlewareAliases array in app/Http/Kernel.php:');
            $this->line("'admin' => \\App\\Http\\Middleware\\AdminMiddleware::class,");
        } else {
            $this->info('✅ Admin middleware is registered');
        }

        // Check if admin routes exist
        if (!File::exists(base_path('routes/admin.php'))) {
            $this->warn('⚠️  Admin routes file not found');
            $this->info('Please create routes/admin.php file with admin routes');
        } else {
            $this->info('✅ Admin routes file exists');
        }

        // Check environment variables
        $adminIds = config('app.admin_telegram_ids');
        if (empty($adminIds)) {
            $this->warn('⚠️  ADMIN_TELEGRAM_IDS not configured');
            $this->info('Please add your Telegram ID to ADMIN_TELEGRAM_IDS in .env file');
        } else {
            $this->info('✅ Admin Telegram IDs configured');
        }

        // Check if AdminServiceProvider is registered
        $appConfigPath = config_path('app.php');
        $appConfig = File::get($appConfigPath);
        
        if (!str_contains($appConfig, 'AdminServiceProvider')) {
            $this->warn('⚠️  AdminServiceProvider not registered');
            $this->info('Please add AdminServiceProvider to the providers array in config/app.php');
        } else {
            $this->info('✅ AdminServiceProvider is registered');
        }

        // Create storage directories
        $backupDir = storage_path('backups');
        if (!File::exists($backupDir)) {
            File::makeDirectory($backupDir, 0755, true);
            $this->info('✅ Created backups directory');
        }

        $this->info("\n🎉 Admin setup check completed!");
        $this->info("\nNext steps:");
        $this->info("1. Configure ADMIN_TELEGRAM_IDS in your .env file");
        $this->info("2. Run: php artisan admin:create-user YOUR_TELEGRAM_ID");
        $this->info("3. Access admin features via /api/admin endpoints");
        
        return 0;
    }
}