<?php

// app/Models/Currency.php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class Currency extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'currencies';
    
    protected $fillable = [
        'code', 'name', 'symbol', 'exchange_rate'
    ];

    protected $casts = [
        'exchange_rate' => 'float'
    ];
}
