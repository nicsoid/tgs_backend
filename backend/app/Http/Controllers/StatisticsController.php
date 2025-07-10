<?php
// app/Http/Controllers/StatisticsController.php

namespace App\Http\Controllers;

use App\Models\ScheduledPost;
use App\Models\PostLog;
use App\Models\Currency;
use Illuminate\Http\Request;
use Carbon\Carbon;

class StatisticsController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $timezone = $user->getTimezone();
        
        // Overall statistics
        $totalPosts = $user->scheduledPosts()->count();
        $totalSent = PostLog::whereHas('post', function($q) use ($user) {
            $q->where('user_id', $user->id);
        })->where('status', 'sent')->count();
        
        $totalRevenue = $user->scheduledPosts()
            ->get()
            ->sum(function($post) use ($user) {
                return $this->convertCurrency(
                    $post->advertiser['amount_paid'],
                    $post->advertiser['currency'],
                    $user->getCurrency()
                );
            });
        
        // Monthly statistics
        $monthlyStats = $this->getMonthlyStats($user, $timezone);
        
        // Top advertisers
        $topAdvertisers = $this->getTopAdvertisers($user);
        
        // Group statistics
        $groupStats = $this->getGroupStats($user);
        
        return response()->json([
            'overall' => [
                'total_posts' => $totalPosts,
                'total_sent' => $totalSent,
                'total_revenue' => $totalRevenue,
                'currency' => $user->getCurrency()
            ],
            'monthly' => $monthlyStats,
            'top_advertisers' => $topAdvertisers,
            'group_stats' => $groupStats
        ]);
    }
    
    public function postDetails(Request $request, $postId)
    {
        $post = ScheduledPost::where('user_id', $request->user()->id)
            ->findOrFail($postId);
        
        $statistics = $post->getStatistics();
        
        return response()->json([
            'post' => $post,
            'statistics' => $statistics,
            'logs' => $post->logs()->orderBy('sent_at', 'desc')->get()
        ]);
    }
    
    private function getMonthlyStats($user, $timezone)
    {
        $sixMonthsAgo = Carbon::now($timezone)->subMonths(6)->startOfMonth();
        
        $stats = PostLog::whereHas('post', function($q) use ($user) {
            $q->where('user_id', $user->id);
        })
        ->where('status', 'sent')
        ->where('sent_at', '>=', $sixMonthsAgo)
        ->get()
        ->groupBy(function($log) use ($timezone) {
            return Carbon::parse($log->sent_at)->timezone($timezone)->format('Y-m');
        })
        ->map(function($logs, $month) use ($user) {
            $revenue = $logs->sum(function($log) use ($user) {
                $post = $log->post;
                return $this->convertCurrency(
                    $post->advertiser['amount_paid'] / $post->total_scheduled,
                    $post->advertiser['currency'],
                    $user->getCurrency()
                );
            });
            
            return [
                'month' => $month,
                'count' => $logs->count(),
                'revenue' => $revenue
            ];
        })
        ->values();
        
        return $stats;
    }
    
    private function getTopAdvertisers($user)
    {
        return $user->scheduledPosts()
            ->get()
            ->groupBy('advertiser.telegram_username')
            ->map(function($posts, $username) use ($user) {
                $totalPaid = $posts->sum(function($post) use ($user) {
                    return $this->convertCurrency(
                        $post->advertiser['amount_paid'],
                        $post->advertiser['currency'],
                        $user->getCurrency()
                    );
                });
                
                return [
                    'username' => $username,
                    'total_posts' => $posts->count(),
                    'total_paid' => $totalPaid,
                    'currency' => $user->getCurrency()
                ];
            })
            ->sortByDesc('total_paid')
            ->take(10)
            ->values();
    }
    
    private function getGroupStats($user)
    {
        return $user->groups()
            ->with(['scheduledPosts' => function($query) use ($user) {
                $query->where('user_id', $user->id);
            }])
            ->get()
            ->map(function($group) {
                return [
                    'group' => $group->only(['_id', 'title', 'member_count']),
                    'total_posts' => $group->scheduledPosts->count(),
                    'last_post' => $group->scheduledPosts()
                        ->orderBy('created_at', 'desc')
                        ->first()
                        ?->created_at
                ];
            });
    }
    
    private function convertCurrency($amount, $from, $to)
    {
        if ($from === $to) {
            return $amount;
        }
        
        $fromCurrency = Currency::where('code', $from)->first();
        $toCurrency = Currency::where('code', $to)->first();
        
        if (!$fromCurrency || !$toCurrency) {
            return $amount;
        }
        
        // Convert to USD first, then to target currency
        $usdAmount = $amount / $fromCurrency->exchange_rate;
        return $usdAmount * $toCurrency->exchange_rate;
    }
}