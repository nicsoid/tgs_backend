<?php
// app/Console/Commands/RefreshGroupInfo.php  refresh member counts for all groups

namespace App\Console\Commands;

use App\Models\Group;
use App\Services\TelegramService;
use Illuminate\Console\Command;

class RefreshGroupInfo extends Command
{
    protected $signature = 'groups:refresh-info 
                           {--group-id= : Refresh info for specific group ID}
                           {--stale-only : Only refresh groups not updated in last 24 hours}';
    
    protected $description = 'Refresh group information including member counts from Telegram';
    
    protected $telegramService;

    public function __construct(TelegramService $telegramService)
    {
        parent::__construct();
        $this->telegramService = $telegramService;
    }

    public function handle()
    {
        $this->info('Starting group information refresh...');
        
        if ($this->option('group-id')) {
            $this->refreshSpecificGroup($this->option('group-id'));
        } elseif ($this->option('stale-only')) {
            $this->refreshStaleGroups();
        } else {
            $this->refreshAllGroups();
        }
        
        $this->info('Group information refresh completed!');
        return 0;
    }

    private function refreshSpecificGroup($groupId)
    {
        $group = Group::find($groupId);
        
        if (!$group) {
            $this->error("Group with ID {$groupId} not found");
            return;
        }
        
        $this->info("Refreshing info for group: {$group->title}");
        
        $result = $this->refreshGroupInfo($group);
        
        if ($result['success']) {
            $this->info("✓ Updated: {$group->title} ({$result['member_count']} members)");
        } else {
            $this->error("✗ Failed: {$group->title} - {$result['error']}");
        }
    }

    private function refreshAllGroups()
    {
        $groups = Group::all();
        $totalGroups = $groups->count();
        
        if ($totalGroups === 0) {
            $this->info('No groups found');
            return;
        }
        
        $this->info("Refreshing info for {$totalGroups} groups...");
        
        $bar = $this->output->createProgressBar($totalGroups);
        $bar->start();
        
        $updated = 0;
        $failed = 0;
        
        foreach ($groups as $group) {
            $result = $this->refreshGroupInfo($group);
            
            if ($result['success']) {
                $updated++;
            } else {
                $failed++;
            }
            
            $bar->advance();
            
            // Small delay to avoid hitting Telegram API rate limits
            usleep(200000); // 0.2 seconds
        }
        
        $bar->finish();
        
        $this->info("\n\nRefresh summary:");
        $this->info("- Total groups: {$totalGroups}");
        $this->info("- Successfully updated: {$updated}");
        $this->info("- Failed: {$failed}");
    }

    private function refreshStaleGroups()
    {
        $staleTime = now()->subHours(24);
        
        $staleGroups = Group::where(function($query) use ($staleTime) {
            $query->where('updated_at', '<', $staleTime)
                  ->orWhere('updated_at', null)
                  ->orWhere('member_count', '=', 0);
        })->get();
        
        $totalStale = $staleGroups->count();
        
        if ($totalStale === 0) {
            $this->info('No stale groups found');
            return;
        }
        
        $this->info("Refreshing info for {$totalStale} stale groups...");
        
        $bar = $this->output->createProgressBar($totalStale);
        $bar->start();
        
        $updated = 0;
        $failed = 0;
        
        foreach ($staleGroups as $group) {
            $result = $this->refreshGroupInfo($group);
            
            if ($result['success']) {
                $updated++;
            } else {
                $failed++;
            }
            
            $bar->advance();
            
            // Small delay to avoid hitting Telegram API rate limits
            usleep(200000); // 0.2 seconds
        }
        
        $bar->finish();
        
        $this->info("\n\nStale refresh summary:");
        $this->info("- Stale groups found: {$totalStale}");
        $this->info("- Successfully updated: {$updated}");
        $this->info("- Failed: {$failed}");
    }

    private function refreshGroupInfo(Group $group)
    {
        try {
            // Get fresh chat info from Telegram
            $chatInfo = $this->telegramService->getChatInfo($group->telegram_id);
            
            if (!$chatInfo) {
                return [
                    'success' => false,
                    'error' => 'Could not fetch chat info from Telegram'
                ];
            }
            
            // Get member count
            $memberCount = 0;
            try {
                $memberCount = $this->telegramService->getChatMemberCount($group->telegram_id);
            } catch (\Exception $e) {
                // Use existing count as fallback
                $memberCount = $group->member_count ?? 0;
            }
            
            // Update group information
            $group->update([
                'title' => $chatInfo['title'],
                'username' => $chatInfo['username'] ?? null,
                'type' => $chatInfo['type'],
                'member_count' => $memberCount,
                'updated_at' => now()
            ]);
            
            return [
                'success' => true,
                'member_count' => $memberCount
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}