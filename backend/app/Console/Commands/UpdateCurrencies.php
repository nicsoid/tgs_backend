<?php
// app/Console/Commands/UpdateCurrencies.php

namespace App\Console\Commands;

use App\Models\Currency;
use Illuminate\Console\Command;
use GuzzleHttp\Client;

class UpdateCurrencies extends Command
{
    protected $signature = 'currencies:update';
    protected $description = 'Update currency exchange rates';

    public function handle()
    {
        $client = new Client();
        
        try {
            // Using a free API like exchangerate-api.com
            $response = $client->get('https://api.exchangerate-api.com/v4/latest/USD');
            $data = json_decode($response->getBody(), true);
            
            foreach ($data['rates'] as $code => $rate) {
                Currency::where('code', $code)->update([
                    'exchange_rate' => $rate,
                    'updated_at' => now()
                ]);
            }
            
            $this->info('Currency rates updated successfully');
        } catch (\Exception $e) {
            $this->error('Failed to update currency rates: ' . $e->getMessage());
        }
    }
}