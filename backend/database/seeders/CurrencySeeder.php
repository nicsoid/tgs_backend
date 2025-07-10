<?php
// database/seeders/CurrencySeeder.php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Currency;

class CurrencySeeder extends Seeder
{
    public function run()
    {
        $currencies = [
            [
                'code' => 'USD',
                'name' => 'US Dollar',
                'symbol' => '$',
                'exchange_rate' => 1.0
            ],
            [
                'code' => 'EUR',
                'name' => 'Euro',
                'symbol' => '€',
                'exchange_rate' => 0.85
            ],
            [
                'code' => 'GBP',
                'name' => 'British Pound',
                'symbol' => '£',
                'exchange_rate' => 0.73
            ],
            [
                'code' => 'UAH',
                'name' => 'Ukrainian Hryvnia',
                'symbol' => '₴',
                'exchange_rate' => 36.5
            ],
            [
                'code' => 'RUB',
                'name' => 'Russian Ruble',
                'symbol' => '₽',
                'exchange_rate' => 75.0
            ],
            [
                'code' => 'PLN',
                'name' => 'Polish Zloty',
                'symbol' => 'zł',
                'exchange_rate' => 4.0
            ],
            [
                'code' => 'CHF',
                'name' => 'Swiss Franc',
                'symbol' => 'CHF',
                'exchange_rate' => 0.92
            ],
            [
                'code' => 'CAD',
                'name' => 'Canadian Dollar',
                'symbol' => 'C$',
                'exchange_rate' => 1.25
            ],
            [
                'code' => 'AUD',
                'name' => 'Australian Dollar',
                'symbol' => 'A$',
                'exchange_rate' => 1.35
            ],
            [
                'code' => 'JPY',
                'name' => 'Japanese Yen',
                'symbol' => '¥',
                'exchange_rate' => 110.0
            ]
        ];

        foreach ($currencies as $currency) {
            Currency::updateOrCreate(
                ['code' => $currency['code']],
                $currency
            );
        }
    }
}