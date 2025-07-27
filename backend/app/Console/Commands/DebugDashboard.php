<?php
// app/Console/Commands/DebugDashboard.php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Group;
use App\Models\ScheduledPost;
use App\Models\PostLog;
use Illuminate\Console\Command;
use Carbon\Carbon;

class DebugDashboard extends Command
{
    protected $signature = 'debug:dashboard {user_id?}';
    protected $description = 'Debug dashboard data and endpoints';

    public function handle()
    {
        $this->info('=== Dashboard Debug Information ===');
        
        $userId = $this->argument('user_id');
        
        if ($userId) {
            $user = User::find($userId);
            if (!$user) {
                $this->error("User with ID {$userId} not found");
                return 1;
            }
        } else {
            $user = User::first();
            if (!$user) {
                $this->error("No users found in database");
                return 1;
            }
        }
        
        $this->info("Testing for user: {$user->first_name} {$user->last_name} (ID: {$user->id})");
        $this->info("Username: @{$user->username}");
        $this->info("");

        // Test 1: User's posts
        $this->info("=== User's Posts ===");
        $posts = $user->scheduledPosts()->get();
        $this->info("Total posts: " . $posts->count());
        
        foreach ($posts->take(3) as $post) {
            $this->info("- Post ID: {$post->id}");
            $this->info("  Groups: " . count($post->group_ids ?? []));
            $this->info("  Schedule times: " . count($post->schedule_times ?? []));
            $this->info("  Content: " . substr($post->content['text'] ?? '', 0, 50) . "...");
            $this->info("  Created: " . $post->created_at);
            $this->info("");
        }

        // Test 2: User's groups
        $this->info("=== User's Groups ===");
        try {
            $userGroupRelations = \DB::connection('mongodb')
                ->table('user_groups')
                ->where('user_id', $user->id)
                ->where('is_admin', true)
                ->get();
            
            $this->info("Admin group relationships: " . $userGroupRelations->count());
            
            foreach ($userGroupRelations->take(3) as $relation) {
                $group = Group::find($relation->group_id);
                if ($group) {
                    $this->info("- Group: {$group->title} (ID: {$group->id})");
                } else {
                    $this->info("- Group ID: {$relation->group_id} (Group not found)");
                }
            }
        } catch (\Exception $e) {
            $this->error("Error fetching user groups: " . $e->getMessage());
        }
        $this->info("");

        // Test 3: Usage stats
        $this->info("=== Usage Stats ===");
        $plan = $user->getSubscriptionPlan();
        $this->info("Plan: " . ($plan->name ?? 'unknown'));
        $this->info("Plan limits: " . json_encode($plan->limits ?? []));
        
        $user->checkAndResetMonthlyUsage();
        $this->info("Usage: " . json_encode($user->usage ?? []));
        $this->info("");

        // Test 4: Post logs
        $this->info("=== Post Logs ===");
        $logs = PostLog::whereHas('post', function($q) use ($user) {
            $q->where('user_id', $user->id);
        })->get();
        
        $this->info("Total logs: " . $logs->count());
        $sentLogs = $logs->where('status', 'sent');
        $this->info("Sent messages: " . $sentLogs->count());
        $failedLogs = $logs->where('status', 'failed');
        $this->info("Failed messages: " . $failedLogs->count());
        $this->info("");

        // Test 5: Statistics calculation
        $this->info("=== Statistics Calculation ===");
        $totalRevenue = $user->scheduledPosts()
            ->get()
            ->sum(function($post) use ($user) {
                $amount = $post->advertiser['amount_paid'] ?? 0;
                return $amount; // Simple sum without currency conversion for now
            });
        
        $this->info("Total revenue: {$totalRevenue}");
        $this->info("");

        // Test 6: API endpoint simulation
        $this->info("=== API Endpoint Simulation ===");
        
        try {
            // Simulate statistics endpoint
            $stats = [
                'overall' => [
                    'total_posts' => $posts->count(),
                    'total_sent' => $sentLogs->count(),
                    'total_revenue' => $totalRevenue,
                    'currency' => $user->getCurrency()
                ]
            ];
            $this->info("Statistics data: " . json_encode($stats, JSON_PRETTY_PRINT));
        } catch (\Exception $e) {
            $this->error("Error generating statistics: " . $e->getMessage());
        }

        try {
            // Simulate usage stats endpoint
            $usage = [
                'usage' => [
                    'groups' => [
                        'used' => $user->usage['groups_count'] ?? 0,
                        'limit' => $plan->limits['groups'] ?? 1,
                        'percentage' => $plan->limits['groups'] > 0 
                            ? round((($user->usage['groups_count'] ?? 0) / $plan->limits['groups']) * 100, 2)
                            : 0
                    ],
                    'messages' => [
                        'used' => $user->usage['messages_sent_this_month'] ?? 0,
                        'limit' => $plan->limits['messages_per_month'] ?? 3,
                        'percentage' => $plan->limits['messages_per_month'] > 0
                            ? round((($user->usage['messages_sent_this_month'] ?? 0) / $plan->limits['messages_per_month']) * 100, 2)
                            : 0
                    ]
                ],
                'plan' => $plan
            ];
            $this->info("Usage data: " . json_encode($usage, JSON_PRETTY_PRINT));
        } catch (\Exception $e) {
            $this->error("Error generating usage stats: " . $e->getMessage());
        }

        // Test 7: Database connections
        $this->info("=== Database Tests ===");
        try {
            $mongoCount = \DB::connection('mongodb')->table('users')->count();
            $this->info("MongoDB users count: {$mongoCount}");
        } catch (\Exception $e) {
            $this->error("MongoDB connection error: " . $e->getMessage());
        }

        $this->info("");
        $this->info("=== Debug Complete ===");
        
        return 0;
    }
}