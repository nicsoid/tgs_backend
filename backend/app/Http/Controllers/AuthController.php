<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class AuthController extends Controller
{
    public function telegramAuth(Request $request)
    {
        Log::info('Telegram auth attempt', $request->all());

        $request->validate([
            'id' => 'required',
            'first_name' => 'required',
            'auth_date' => 'required',
            'hash' => 'required'
        ]);

        // Verify Telegram authentication
        if (!$this->verifyTelegramAuth($request->all())) {
            Log::error('Telegram auth verification failed', [
                'data' => $request->except('hash'),
                'provided_hash' => $request->hash
            ]);
            return response()->json(['error' => 'Invalid authentication'], 401);
        }

        try {
            // Prepare user data with default values
            $userData = [
                'first_name' => $request->first_name,
                'last_name' => $request->last_name ?? null,
                'username' => $request->username ?? null,
                'photo_url' => $request->photo_url ?? null,
                'auth_date' => Carbon::now(), // Use current time instead of parsing auth_date
                'settings' => [
                    'timezone' => 'UTC',
                    'language' => 'en',
                    'currency' => 'USD'
                ],
                'subscription' => [
                    'plan' => 'free',
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
                    'last_reset_date' => Carbon::now()->startOfMonth()->toDateTimeString()
                ]
            ];

            // Find or create user
            $user = User::where('telegram_id', (string)$request->id)->first();
            
            if ($user) {
                // Update existing user
                $user->update([
                    'first_name' => $request->first_name,
                    'last_name' => $request->last_name ?? $user->last_name,
                    'username' => $request->username ?? $user->username,
                    'photo_url' => $request->photo_url ?? $user->photo_url,
                    'auth_date' => Carbon::now()
                ]);
            } else {
                // Create new user
                $user = User::create(array_merge(['telegram_id' => (string)$request->id], $userData));
            }

            $token = JWTAuth::fromUser($user);

            Log::info('Telegram auth successful', ['user_id' => $user->id]);

            return response()->json([
                'token' => $token,
                'user' => $user
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error creating/updating user', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'error' => 'Failed to process authentication',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    private function verifyTelegramAuth($auth_data)
    {
        $check_hash = $auth_data['hash'];
        unset($auth_data['hash']);
        
        // Create data-check-string
        $data_check_arr = [];
        foreach ($auth_data as $key => $value) {
            if ($value !== null && $value !== '') {
                $data_check_arr[] = $key . '=' . $value;
            }
        }
        sort($data_check_arr);
        
        $data_check_string = implode("\n", $data_check_arr);
        
        // Get bot token
        $bot_token = config('services.telegram.bot_token');
        if (!$bot_token) {
            Log::error('Telegram bot token not configured');
            return false;
        }
        
        // Create secret key
        $secret_key = hash('sha256', $bot_token, true);
        
        // Calculate hash
        $hash = hash_hmac('sha256', $data_check_string, $secret_key);
        
        Log::info('Telegram auth verification', [
            'data_check_string' => $data_check_string,
            'calculated_hash' => $hash,
            'provided_hash' => $check_hash,
            'bot_token_exists' => !empty($bot_token)
        ]);
        
        // Compare hashes
        return hash_equals($hash, $check_hash);
    }
}