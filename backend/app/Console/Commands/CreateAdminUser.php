<?php
// app/Console/Commands/CreateAdminUser.php - Helper command to create admin users
namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class CreateAdminUser extends Command
{
    protected $signature = 'admin:create-user 
                           {telegram_id : Telegram ID of the user}
                           {--username= : Telegram username}
                           {--first-name= : First name}
                           {--last-name= : Last name}';
    
    protected $description = 'Create an admin user account';

    public function handle()
    {
        $telegramId = $this->argument('telegram_id');
        $username = $this->option('username') ?? 'admin_' . $telegramId;
        $firstName = $this->option('first-name') ?? 'Admin';
        $lastName = $this->option('last-name') ?? 'User';

        // Check if user already exists
        $existingUser = User::where('telegram_id', $telegramId)->first();
        
        if ($existingUser) {
            $this->warn("User with Telegram ID {$telegramId} already exists.");
            
            if ($this->confirm('Do you want to promote them to admin?')) {
                $settings = $existingUser->settings;
                $settings['is_admin'] = true;
                $settings['promoted_at'] = now();
                $existingUser->settings = $settings;
                $existingUser->save();
                
                $this->info("✅ User @{$existingUser->username} promoted to admin.");
            }
            
            return 0;
        }

        // Create new admin user
        $user = User::create([
            'telegram_id' => $telegramId,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'username' => $username,
            'auth_date' => now(),
            'settings' => [
                'timezone' => 'UTC',
                'language' => 'en',
                'currency' => 'USD',
                'is_admin' => true,
                'promoted_at' => now()
            ],
            'subscription' => [
                'plan' => 'ultra', // Give admin users ultra plan
                'status' => 'active',
                'current_period_start' => null,
                'current_period_end' => null,
                'stripe_customer_id' => null,
                'stripe_subscription_id' => null,
                'cancel_at_period_end' => false
            ],
            'usage' => [
                'groups_count' => 0,
                'messages_sent_this_month' => 0,
                'last_reset_date' => now()->startOfMonth()->toDateTimeString()
            ]
        ]);

        $this->info("✅ Admin user created successfully!");
        $this->table(['Field', 'Value'], [
            ['ID', $user->id],
            ['Telegram ID', $user->telegram_id],
            ['Username', '@' . $user->username],
            ['Name', $user->first_name . ' ' . $user->last_name],
            ['Admin', 'Yes'],
            ['Plan', 'Ultra']
        ]);

        $this->warn("⚠️  Don't forget to add this Telegram ID to ADMIN_TELEGRAM_IDS in your .env file!");
        
        return 0;
    }
}