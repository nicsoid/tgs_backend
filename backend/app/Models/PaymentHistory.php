<?php
// app/Models/PaymentHistory.php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class PaymentHistory extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'payment_history';
    
    protected $fillable = [
        'user_id', 'stripe_payment_intent_id', 'amount',
        'currency', 'status', 'plan', 'period_start', 'period_end'
    ];

    protected $casts = [
        'amount' => 'float',
        'period_start' => 'datetime',
        'period_end' => 'datetime'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}