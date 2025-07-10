<?php
// app/Models/SubscriptionPlan.php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class SubscriptionPlan extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'subscription_plans';
    
    protected $fillable = [
        'name', 'display_name', 'price', 'currency',
        'limits', 'features', 'stripe_price_id', 
        'stripe_product_id', 'is_active'
    ];

    protected $casts = [
        'limits' => 'array',
        'features' => 'array',
        'is_active' => 'boolean',
        'price' => 'float'
    ];

    public static function getActivePlans()
    {
        return self::where('is_active', true)->orderBy('price', 'asc')->get();
    }
}
