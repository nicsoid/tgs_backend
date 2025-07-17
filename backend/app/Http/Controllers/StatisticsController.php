<?php
// app/Http/Controllers/StatisticsController.php - Fixed Group Statistics

namespace App\Http\Controllers;

use App\Models\ScheduledPost;
use App\Models\PostLog;
use App\Models\Currency;
use App\Models\Group;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

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
        
        // Group statistics - Fixed implementation
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
        try {
            // Get user's admin groups from the user_groups collection
            $userGroupRelations = DB::connection('mongodb')
                ->table('user_groups')
                ->where('user_id', $user->id)
                ->where('is_admin', true)
                ->get();
            
            if ($userGroupRelations->isEmpty()) {
                return [];
            }
            
            $groupIds = $userGroupRelations->pluck('group_id')->toArray();
            
            // Get the groups
            $groups = Group::whereIn('_id', $groupIds)->get();
            
            $groupStats = [];
            
            foreach ($groups as $group) {
                $groupId = $group->id ?? $group->_id;
                
                // Count posts that include this group
                $postsInGroup = $user->scheduledPosts()
                    ->where(function($query) use ($groupId) {
                        $query->whereJsonContains('group_ids', $groupId)
                              ->orWhere('group_id', $groupId); // For backward compatibility
                    })
                    ->get();
                
                // Get the most recent post for this group
                $lastPost = $postsInGroup
                    ->sortByDesc('created_at')
                    ->first();
                
                $groupStats[] = [
                    'group' => [
                        '_id' => $groupId,
                        'title' => $group->title,
                        'member_count' => $group->member_count ?? 0,
                        'username' => $group->username
                    ],
                    'total_posts' => $postsInGroup->count(),
                    'last_post' => $lastPost ? $lastPost->created_at : null,
                    'total_messages_sent' => $this->getMessagesSentForGroup($user, $groupId),
                    'total_revenue' => $this->getRevenueForGroup($user, $groupId, $postsInGroup)
                ];
            }
            
            // Sort by total posts descending
            usort($groupStats, function($a, $b) {
                return $b['total_posts'] - $a['total_posts'];
            });
            
            return $groupStats;
            
        } catch (\Exception $e) {
            \Log::error('Error fetching group statistics', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return [];
        }
    }
    
    private function getMessagesSentForGroup($user, $groupId)
    {
        try {
            return PostLog::whereHas('post', function($q) use ($user) {
                $q->where('user_id', $user->id);
            })
            ->where('group_id', $groupId)
            ->where('status', 'sent')
            ->count();
        } catch (\Exception $e) {
            \Log::error('Error getting messages sent for group', [
                'group_id' => $groupId,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }
    
    private function getRevenueForGroup($user, $groupId, $postsInGroup)
    {
        try {
            $totalRevenue = 0;
            
            foreach ($postsInGroup as $post) {
                // Calculate revenue per group for this post
                $groupCount = count($post->group_ids ?? [$post->group_id]);
                $revenuePerGroup = $post->advertiser['amount_paid'] / max(1, $groupCount);
                
                $totalRevenue += $this->convertCurrency(
                    $revenuePerGroup,
                    $post->advertiser['currency'],
                    $user->getCurrency()
                );
            }
            
            return round($totalRevenue, 2);
        } catch (\Exception $e) {
            \Log::error('Error calculating revenue for group', [
                'group_id' => $groupId,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }
    
    private function convertCurrency($amount, $from, $to)
    {
        if ($from === $to) {
            return $amount;
        }
        
        try {
            $fromCurrency = Currency::where('code', $from)->first();
            $toCurrency = Currency::where('code', $to)->first();
            
            if (!$fromCurrency || !$toCurrency) {
                return $amount;
            }
            
            // Convert to USD first, then to target currency
            $usdAmount = $amount / $fromCurrency->exchange_rate;
            return $usdAmount * $toCurrency->exchange_rate;
        } catch (\Exception $e) {
            \Log::error('Currency conversion error', [
                'amount' => $amount,
                'from' => $from,
                'to' => $to,
                'error' => $e->getMessage()
            ]);
            return $amount;
        }
    }
}