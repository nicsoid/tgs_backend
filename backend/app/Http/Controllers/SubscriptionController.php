<?php
// app/Http/Controllers/SubscriptionController.php

namespace App\Http\Controllers;

use App\Models\SubscriptionPlan;
use App\Models\PaymentHistory;
use App\Models\User;
use Illuminate\Http\Request;
use Stripe\Stripe;
use Stripe\Customer;
use Stripe\Subscription;
use Stripe\PaymentMethod;
use Carbon\Carbon;

class SubscriptionController extends Controller
{
    public function __construct()
    {
        Stripe::setApiKey(config('services.stripe.secret'));
    }

    public function getPlans(Request $request)
    {
        $plans = SubscriptionPlan::getActivePlans();
        $currentPlan = $request->user()->getSubscriptionPlan();
        $usage = $request->user()->usage;

        return response()->json([
            'plans' => $plans,
            'current_plan' => $currentPlan,
            'usage' => $usage,
            'subscription' => $request->user()->subscription
        ]);
    }

    public function createCheckoutSession(Request $request)
    {
        $request->validate([
            'plan' => 'required|in:pro,ultra',
            'payment_method_id' => 'required|string'
        ]);

        $user = $request->user();
        $plan = SubscriptionPlan::where('name', $request->plan)->firstOrFail();

        try {
            // Create or retrieve Stripe customer
            if (!$user->subscription['stripe_customer_id']) {
                $customer = Customer::create([
                    'email' => $user->username . '@telegram.user',
                    'name' => $user->first_name . ' ' . $user->last_name,
                    'metadata' => [
                        'telegram_id' => $user->telegram_id
                    ]
                ]);
                
                $user->subscription['stripe_customer_id'] = $customer->id;
                $user->save();
            }

            // Attach payment method to customer
            $paymentMethod = PaymentMethod::retrieve($request->payment_method_id);
            $paymentMethod->attach(['customer' => $user->subscription['stripe_customer_id']]);

            // Set as default payment method
            Customer::update($user->subscription['stripe_customer_id'], [
                'invoice_settings' => ['default_payment_method' => $request->payment_method_id]
            ]);

            // Create subscription
            $subscription = Subscription::create([
                'customer' => $user->subscription['stripe_customer_id'],
                'items' => [['price' => $plan->stripe_price_id]],
                'expand' => ['latest_invoice.payment_intent']
            ]);

            // Update user subscription info
            $user->subscription = array_merge($user->subscription, [
                'plan' => $plan->name,
                'status' => $subscription->status,
                'stripe_subscription_id' => $subscription->id,
                'current_period_start' => Carbon::createFromTimestamp($subscription->current_period_start),
                'current_period_end' => Carbon::createFromTimestamp($subscription->current_period_end),
                'cancel_at_period_end' => false
            ]);
            $user->save();

            // Record payment
            PaymentHistory::create([
                'user_id' => $user->id,
                'stripe_payment_intent_id' => $subscription->latest_invoice->payment_intent->id,
                'amount' => $plan->price,
                'currency' => $plan->currency,
                'status' => 'succeeded',
                'plan' => $plan->name,
                'period_start' => $user->subscription['current_period_start'],
                'period_end' => $user->subscription['current_period_end']
            ]);

            return response()->json([
                'subscription' => $subscription,
                'message' => 'Subscription created successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage()
            ], 400);
        }
    }

    public function cancelSubscription(Request $request)
    {
        $user = $request->user();

        if (!$user->subscription['stripe_subscription_id']) {
            return response()->json(['error' => 'No active subscription'], 400);
        }

        try {
            $subscription = Subscription::update(
                $user->subscription['stripe_subscription_id'],
                ['cancel_at_period_end' => true]
            );

            $user->subscription['cancel_at_period_end'] = true;
            $user->save();

            return response()->json([
                'message' => 'Subscription will be cancelled at the end of the current period',
                'cancel_at' => $user->subscription['current_period_end']
            ]);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function resumeSubscription(Request $request)
    {
        $user = $request->user();

        if (!$user->subscription['stripe_subscription_id']) {
            return response()->json(['error' => 'No active subscription'], 400);
        }

        try {
            $subscription = Subscription::update(
                $user->subscription['stripe_subscription_id'],
                ['cancel_at_period_end' => false]
            );

            $user->subscription['cancel_at_period_end'] = false;
            $user->save();

            return response()->json([
                'message' => 'Subscription resumed successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function getPaymentHistory(Request $request)
    {
        $history = $request->user()->paymentHistory()
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json($history);
    }

    public function handleWebhook(Request $request)
    {
        $endpoint_secret = config('services.stripe.webhook_secret');
        $payload = $request->getContent();
        $sig_header = $request->header('Stripe-Signature');

        try {
            $event = \Stripe\Webhook::constructEvent(
                $payload, $sig_header, $endpoint_secret
            );
        } catch(\UnexpectedValueException $e) {
            return response()->json(['error' => 'Invalid payload'], 400);
        } catch(\Stripe\Exception\SignatureVerificationException $e) {
            return response()->json(['error' => 'Invalid signature'], 400);
        }

        switch ($event->type) {
            case 'customer.subscription.updated':
                $this->handleSubscriptionUpdate($event->data->object);
                break;
            case 'customer.subscription.deleted':
                $this->handleSubscriptionCancelled($event->data->object);
                break;
            case 'invoice.payment_succeeded':
                $this->handlePaymentSucceeded($event->data->object);
                break;
            case 'invoice.payment_failed':
                $this->handlePaymentFailed($event->data->object);
                break;
        }

        return response()->json(['status' => 'success']);
    }

    private function handleSubscriptionUpdate($subscription)
    {
        $user = User::where('subscription.stripe_subscription_id', $subscription->id)->first();
        
        if ($user) {
            $user->subscription['status'] = $subscription->status;
            $user->subscription['current_period_start'] = Carbon::createFromTimestamp($subscription->current_period_start);
            $user->subscription['current_period_end'] = Carbon::createFromTimestamp($subscription->current_period_end);
            $user->save();
        }
    }

    private function handleSubscriptionCancelled($subscription)
    {
        $user = User::where('subscription.stripe_subscription_id', $subscription->id)->first();
        
        if ($user) {
            $user->subscription = [
                'plan' => 'free',
                'status' => 'cancelled',
                'current_period_start' => null,
                'current_period_end' => null,
                'stripe_customer_id' => $user->subscription['stripe_customer_id'],
                'stripe_subscription_id' => null,
                'cancel_at_period_end' => false
            ];
            $user->save();
        }
    }

    private function handlePaymentSucceeded($invoice)
    {
        $user = User::where('subscription.stripe_customer_id', $invoice->customer)->first();
        
        if ($user && $invoice->subscription) {
            PaymentHistory::create([
                'user_id' => $user->id,
                'stripe_payment_intent_id' => $invoice->payment_intent,
                'amount' => $invoice->amount_paid / 100,
                'currency' => strtoupper($invoice->currency),
                'status' => 'succeeded',
                'plan' => $user->subscription['plan'],
                'period_start' => Carbon::createFromTimestamp($invoice->period_start),
                'period_end' => Carbon::createFromTimestamp($invoice->period_end)
            ]);
        }
    }

    private function handlePaymentFailed($invoice)
    {
        $user = User::where('subscription.stripe_customer_id', $invoice->customer)->first();
        
        if ($user) {
            $user->subscription['status'] = 'past_due';
            $user->save();
            
            PaymentHistory::create([
                'user_id' => $user->id,
                'stripe_payment_intent_id' => $invoice->payment_intent,
                'amount' => $invoice->amount_paid / 100,
                'currency' => strtoupper($invoice->currency),
                'status' => 'failed',
                'plan' => $user->subscription['plan'],
                'period_start' => Carbon::createFromTimestamp($invoice->period_start),
                'period_end' => Carbon::createFromTimestamp($invoice->period_end)
            ]);
        }
    }
}