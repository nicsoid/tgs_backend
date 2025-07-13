<?php
// Create this file: app/Console/Commands/SetTelegramWebhook.php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\TelegramService;

class SetTelegramWebhook extends Command
{
    protected $signature = 'telegram:webhook {url}';
    protected $description = 'Set Telegram webhook URL';
    
    public function handle(TelegramService $telegramService)
    {
        $url = $this->argument('url');
        
        $this->info("Setting webhook to: {$url}");
        
        $result = $telegramService->setWebhook($url);
        
        if ($result && $result['ok']) {
            $this->info('Webhook set successfully!');
            $this->info('Description: ' . $result['description']);
        } else {
            $this->error('Failed to set webhook');
            if ($result) {
                $this->error('Error: ' . ($result['description'] ?? 'Unknown error'));
            }
        }
    }
}

// Run this command:
// php artisan telegram:webhook https://yourdomain.com/api/telegram/webhook