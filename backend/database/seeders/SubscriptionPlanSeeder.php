<?php
// database/seeders/SubscriptionPlanSeeder.php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\SubscriptionPlan;

class SubscriptionPlanSeeder extends Seeder
{
    public function run()
    {
        $plans = [
            [
                'name' => 'free',
                'display_name' => 'Free',
                'price' => 0,
                'currency' => 'USD',
                'limits' => [
                    'groups' => 1,
                    'messages_per_month' => 3
                ],
                'features' => [
                    '1 Telegram group',
                    '3 messages per month',
                    'Basic statistics',
                    'Calendar view'
                ],
                'stripe_price_id' => null,
                'stripe_product_id' => null,
                'is_active' => true
            ],
            [
                'name' => 'pro',
                'display_name' => 'Pro',
                'price' => 7,
                'currency' => 'USD',
                'limits' => [
                    'groups' => 3,
                    'messages_per_month' => 20
                ],
                'features' => [
                    '3 Telegram groups',
                    '20 messages per month',
                    'Advanced statistics',
                    'Calendar view',
                    'Priority support',
                    'Export data'
                ],
                'stripe_price_id' => env('STRIPE_PRICE_ID_PRO', 'price_pro_monthly'),
                'stripe_product_id' => env('STRIPE_PRODUCT_ID_PRO', 'prod_pro'),
                'is_active' => true
            ],
            [
                'name' => 'ultra',
                'display_name' => 'Ultra',
                'price' => 30,
                'currency' => 'USD',
                'limits' => [
                    'groups' => 10,
                    'messages_per_month' => 200
                ],
                'features' => [
                    '10 Telegram groups',
                    '200 messages per month',
                    'Advanced statistics',
                    'Calendar view',
                    'Priority support',
                    'Export data',
                    'API access',
                    'Custom branding'
                ],
                'stripe_price_id' => env('STRIPE_PRICE_ID_ULTRA', 'price_ultra_monthly'),
                'stripe_product_id' => env('STRIPE_PRODUCT_ID_ULTRA', 'prod_ultra'),
                'is_active' => true
            ]
        ];

        foreach ($plans as $plan) {
            SubscriptionPlan::updateOrCreate(
                ['name' => $plan['name']],
                $plan
            );
        }
    }
}