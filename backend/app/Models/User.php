<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;
use Tymon\JWTAuth\Contracts\JWTSubject;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Carbon\Carbon;

class User extends Model implements AuthenticatableContract, JWTSubject
{
    use Authenticatable;

    protected $connection = 'mongodb';
    protected $collection = 'users';
    
    protected $fillable = [
        'telegram_id', 'first_name', 'last_name', 
        'username', 'photo_url', 'auth_date', 'settings',
        'subscription', 'usage'
    ];

    protected $casts = [
        'settings' => 'array',
        'subscription' => 'array',
        'usage' => 'array',
        'auth_date' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    protected $dates = ['auth_date', 'created_at', 'updated_at'];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        
        // Set default values properly
        $this->attributes['settings'] = $this->attributes['settings'] ?? [
            'timezone' => 'UTC',
            'language' => 'en',
            'currency' => 'USD'
        ];
        
        $this->attributes['subscription'] = $this->attributes['subscription'] ?? [
            'plan' => 'free',
            'status' => 'active',
            'current_period_start' => null,
            'current_period_end' => null,
            'stripe_customer_id' => null,
            'stripe_subscription_id' => null,
            'cancel_at_period_end' => false
        ];
        
        $this->attributes['usage'] = $this->attributes['usage'] ?? [
            'groups_count' => 0,
            'messages_sent_this_month' => 0,
            'last_reset_date' => null
        ];
    }

    public function groups()
    {
        return $this->belongsToMany(Group::class, 'user_groups', 'user_id', 'group_id')
                    ->withPivot('is_admin', 'permissions', 'added_at', 'last_verified');
    }

    public function scheduledPosts()
    {
        return $this->hasMany(ScheduledPost::class);
    }

    public function paymentHistory()
    {
        return $this->hasMany(PaymentHistory::class);
    }

    public function getSubscriptionPlan()
    {
        return SubscriptionPlan::where('name', $this->subscription['plan'] ?? 'free')->first();
    }

    public function canAddGroup()
    {
        $plan = $this->getSubscriptionPlan();
        if (!$plan) {
            return false;
        }
        return ($this->usage['groups_count'] ?? 0) < ($plan->limits['groups'] ?? 1);
    }

    public function canScheduleMessage()
    {
        $plan = $this->getSubscriptionPlan();
        if (!$plan) {
            return false;
        }
        $this->checkAndResetMonthlyUsage();
        return ($this->usage['messages_sent_this_month'] ?? 0) < ($plan->limits['messages_per_month'] ?? 3);
    }

    public function incrementMessageCount()
    {
        $this->checkAndResetMonthlyUsage();
        $usage = $this->usage;
        $usage['messages_sent_this_month'] = ($usage['messages_sent_this_month'] ?? 0) + 1;
        $this->usage = $usage;
        $this->save();
    }

    public function incrementGroupCount()
    {
        $usage = $this->usage;
        $usage['groups_count'] = ($usage['groups_count'] ?? 0) + 1;
        $this->usage = $usage;
        $this->save();
    }

    public function decrementGroupCount()
    {
        $usage = $this->usage;
        $usage['groups_count'] = max(0, ($usage['groups_count'] ?? 0) - 1);
        $this->usage = $usage;
        $this->save();
    }

    public function checkAndResetMonthlyUsage()
    {
        $usage = $this->usage;
        $lastResetDate = isset($usage['last_reset_date']) && $usage['last_reset_date'] 
            ? Carbon::parse($usage['last_reset_date']) 
            : Carbon::now()->subMonth();

        if ($lastResetDate->lt(Carbon::now()->startOfMonth())) {
            $usage['messages_sent_this_month'] = 0;
            $usage['last_reset_date'] = Carbon::now()->startOfMonth()->toDateTimeString();
            $this->usage = $usage;
            $this->save();
        }
    }

    public function getTimezone()
    {
        return $this->settings['timezone'] ?? 'UTC';
    }

    public function getLanguage()
    {
        return $this->settings['language'] ?? 'en';
    }

    public function getCurrency()
    {
        return $this->settings['currency'] ?? 'USD';
    }

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [];
    }
    
    // Manual Stripe customer methods
    public function stripeCustomerId()
    {
        return $this->subscription['stripe_customer_id'] ?? null;
    }
    
    public function hasStripeCustomer()
    {
        return !empty($this->subscription['stripe_customer_id']);
    }
}