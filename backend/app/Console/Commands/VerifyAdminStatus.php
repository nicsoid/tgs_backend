<?php
// app/Console/Commands/VerifyAdminStatus.php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class VerifyAdminStatus extends Command
{
    protected $signature = 'admin:verify 
                           {--user-id= : Verify admin status for specific user ID}
                           {--all : Verify admin status for all users}
                           {--stale-only : Only verify relationships not checked in last 24 hours}';
    
    protected $description = 'Verify admin status for users in their groups';
    
    protected $telegramService;

    public function __construct(TelegramService $telegramService)
    {
        parent::__construct();
        $this->telegramService = $telegramService;
    }

    public function handle()
    {
        $this->info('Starting admin status verification...');
        
        if ($this->option('user-id')) {
            $this->verifySpecificUser($this->option('user-id'));
        } elseif ($this->option('all')) {
            $this->verifyAllUsers();
        } elseif ($this->option('stale-only')) {
            $this->verifyStaleRelationships();
        } else {
            $this->error('Please specify --user-id, --all, or --stale-only option');
            return 1;
        }
        
        $this->info('Admin status verification completed!');
        return 0;
    }

    private function verifySpecificUser($userId)
    {
        $user = User::find($userId);
        
        if (!$user) {
            $this->error("User with ID {$userId} not found");
            return;
        }
        
        $this->info("Verifying admin status for user: {$user->first_name} (ID: {$user->id})");
        
        $result = $this->telegramService->verifyUserAdminStatusForAllGroups($user);
        
        $this->info("Verification completed for user {$user->id}:");
        $this->info("- Updated relationships: {$result['updated']}");
        $this->info("- Removed relationships: {$result['removed']}");
        
        if ($result['removed'] > 0) {
            $this->warn("User lost admin access to {$result['removed']} group(s)");
        }
    }

    private function verifyAllUsers()
    {
        $this->info('Verifying admin status for all users...');
        
        $users = User::all();
        $totalUsers = $users->count();
        
        if ($totalUsers === 0) {
            $this->info('No users found');
            return;
        }
        
        $bar = $this->output->createProgressBar($totalUsers);
        $bar->start();
        
        $totalUpdated = 0;
        $totalRemoved = 0;
        $usersWithChanges = 0;
        
        foreach ($users as $user) {
            try {
                $result = $this->telegramService->verifyUserAdminStatusForAllGroups($user);
                
                $totalUpdated += $result['updated'];
                $totalRemoved += $result['removed'];
                
                if ($result['updated'] > 0 || $result['removed'] > 0) {
                    $usersWithChanges++;
                }
                
                $bar->advance();
                
                // Small delay to avoid hitting Telegram API rate limits
                usleep(100000); // 0.1 seconds
                
            } catch (\Exception $e) {
                $this->error("\nError verifying user {$user->id}: " . $e->getMessage());
                $bar->advance();
            }
        }
        
        $bar->finish();
        
        $this->info("\n\nVerification summary:");
        $this->info("- Total users processed: {$totalUsers}");
        $this->info("- Users with changes: {$usersWithChanges}");
        $this->info("- Total relationships updated: {$totalUpdated}");
        $this->info("- Total relationships removed: {$totalRemoved}");
        
        if ($totalRemoved > 0) {
            $this->warn("Total admin access revocations: {$totalRemoved}");
        }
    }

    private function verifyStaleRelationships()
    {
        $this->info('Verifying stale admin relationships (not verified in last 24 hours)...');
        
        $staleTime = Carbon::now()->subHours(24);
        
        // Get relationships that haven't been verified recently
        $staleRelationships = DB::connection('mongodb')
            ->table('user_groups')
            ->where(function($query) use ($staleTime) {
                $query->where('last_verified', '<', $staleTime)
                      ->orWhere('last_verified', null);
            })
            ->get();
        
        $totalStale = $staleRelationships->count();
        
        if ($totalStale === 0) {
            $this->info('No stale relationships found');
            return;
        }
        
        $this->info("Found {$totalStale} stale relationships to verify");
        
        $bar = $this->output->createProgressBar($totalStale);
        $bar->start();
        
        $verified = 0;
        $removed = 0;
        $errors = 0;
        
        // Group by user to batch verify
        $relationshipsByUser = $staleRelationships->groupBy('user_id');
        
        foreach ($relationshipsByUser as $userId => $userRelationships) {
            try {
                $user = User::find($userId);
                
                if (!$user) {
                    $this->warn("\nUser {$userId} not found, skipping");
                    $bar->advance($userRelationships->count());
                    continue;
                }
                
                $result = $this->telegramService->verifyUserAdminStatusForAllGroups($user);
                
                $verified += $result['updated'];
                $removed += $result['removed'];
                
                $bar->advance($userRelationships->count());
                
                // Small delay to avoid hitting Telegram API rate limits
                usleep(200000); // 0.2 seconds
                
            } catch (\Exception $e) {
                $this->error("\nError verifying user {$userId}: " . $e->getMessage());
                $errors++;
                $bar->advance($userRelationships->count());
            }
        }
        
        $bar->finish();
        
        $this->info("\n\nStale verification summary:");
        $this->info("- Stale relationships found: {$totalStale}");
        $this->info("- Relationships verified/updated: {$verified}");
        $this->info("- Relationships removed: {$removed}");
        $this->info("- Errors encountered: {$errors}");
        
        if ($removed > 0) {
            $this->warn("Admin access revoked for {$removed} stale relationship(s)");
        }
    }
}