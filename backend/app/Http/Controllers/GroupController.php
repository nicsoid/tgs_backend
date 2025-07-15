<?php
// app/Http/Controllers/GroupController.php

namespace App\Http\Controllers;

use App\Models\Group;
use App\Services\TelegramService;
use Illuminate\Http\Request;

class GroupController extends Controller
{
    protected $telegramService;

    public function __construct(TelegramService $telegramService)
    {
        $this->telegramService = $telegramService;
    }

    public function index(Request $request)
    {
        $user = $request->user();
        
        \Log::info('=== FETCHING USER GROUPS ===', [
            'user_id' => $user->id,
            'user_id_type' => gettype($user->id)
        ]);
        
        try {
            // Only verify admin status if explicitly requested or if it's been a while
            $shouldVerify = $request->get('verify_admin', false) || $this->shouldAutoVerify($user);
            
            $verificationResult = ['updated' => 0, 'removed' => 0];
            
            if ($shouldVerify) {
                $verificationResult = $this->telegramService->verifyUserAdminStatusForAllGroups($user);
                
                \Log::info('Admin verification during group fetch', [
                    'user_id' => $user->id,
                    'verification_result' => $verificationResult
                ]);
            }

            // Now get the updated relationships
            $userGroupRelations = \DB::connection('mongodb')
                ->table('user_groups')
                ->where('user_id', $user->id)
                ->where('is_admin', true)  // Only get admin relationships
                ->get();
            
            \Log::info('User group relations after verification', [
                'query_user_id' => $user->id,
                'admin_relations_found' => $userGroupRelations->count(),
                'verification_triggered' => $shouldVerify
            ]);
            
            if ($userGroupRelations->isEmpty()) {
                \Log::info('No admin relationships found for user');
                return response()->json([], 200, [
                    'X-Verification-Updated' => $verificationResult['updated'],
                    'X-Verification-Removed' => $verificationResult['removed']
                ]);
            }
            
            // Extract group IDs
            $groupIds = $userGroupRelations->pluck('group_id')->toArray();
            
            // Get groups
            $groups = \App\Models\Group::whereIn('_id', $groupIds)->get();
            
            // Ensure proper ID format
            $groups = $groups->map(function($group) {
                if (!isset($group->id)) {
                    $group->id = (string)$group->_id;
                }
                return $group;
            });
            
            \Log::info('=== FINAL RESULT ===', [
                'groups_count' => $groups->count(),
                'verification_result' => $verificationResult
            ]);
            
            // For backward compatibility, return just the groups array
            // but include verification result in headers for frontend to optionally use
            return response()->json($groups, 200, [
                'X-Verification-Updated' => $verificationResult['updated'],
                'X-Verification-Removed' => $verificationResult['removed']
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Exception in group fetch', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'error' => 'Failed to fetch groups',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Check if automatic verification should be triggered
     */
    private function shouldAutoVerify($user)
    {
        // Get the last verification time for any of user's groups
        $lastVerification = \DB::connection('mongodb')
            ->table('user_groups')
            ->where('user_id', $user->id)
            ->where('last_verified', '!=', null)
            ->orderBy('last_verified', 'desc')
            ->first();

        if (!$lastVerification) {
            return true; // Never verified
        }

        // Auto-verify if last verification was more than 1 hour ago
        $lastVerifiedTime = \Carbon\Carbon::parse($lastVerification->last_verified);
        return $lastVerifiedTime->diffInHours(now()) > 1;
    }

    public function addGroup(Request $request)
    {
        $request->validate([
            'group_id' => 'required|string', // Telegram group ID or username
        ]);
        
        $user = $request->user();
        
        if (!$user->canAddGroup()) {
            $plan = $user->getSubscriptionPlan();
            return response()->json([
                'error' => 'Group limit reached',
                'message' => "Your {$plan->display_name} plan allows up to {$plan->limits['groups']} groups."
            ], 403);
        }
        
        try {
            $groupId = $request->group_id;
            
            // If it starts with @, remove it
            if (str_starts_with($groupId, '@')) {
                $groupId = substr($groupId, 1);
            }
            
            // Get chat info from Telegram
            $chatInfo = $this->telegramService->getChatInfo($groupId);
            
            if (!$chatInfo) {
                return response()->json([
                    'error' => 'Group not found',
                    'message' => 'Could not find the group. Make sure the bot is added to the group.'
                ], 404);
            }
            
            // Check if user is admin
            $isUserAdmin = $this->telegramService->checkUserIsAdmin(
                $chatInfo['id'],
                $user->telegram_id
            );
            
            if (!$isUserAdmin) {
                return response()->json([
                    'error' => 'Not authorized',
                    'message' => 'You must be an admin in this group to add it.'
                ], 403);
            }
            
            // Get member count safely with better error handling
            $memberCount = 0;
            try {
                $memberCount = $this->telegramService->getChatMemberCount($chatInfo['id']);
                \Log::info('Successfully got member count', [
                    'chat_id' => $chatInfo['id'],
                    'member_count' => $memberCount
                ]);
            } catch (\Exception $e) {
                \Log::warning('Could not get member count', [
                    'chat_id' => $chatInfo['id'],
                    'error' => $e->getMessage()
                ]);
                // Try to get approximate member count from chat info if available
                if (isset($chatInfo['approximate_member_count'])) {
                    $memberCount = $chatInfo['approximate_member_count'];
                } elseif (isset($chatInfo['member_count'])) {
                    $memberCount = $chatInfo['member_count'];
                }
            }
            
            // Create or update group
            $group = Group::updateOrCreate(
                ['telegram_id' => (string)$chatInfo['id']],
                [
                    'title' => $chatInfo['title'],
                    'username' => $chatInfo['username'] ?? null,
                    'type' => $chatInfo['type'],
                    'photo_url' => null,
                    'member_count' => $memberCount,
                    'updated_at' => now() // Force update timestamp
                ]
            );
            
            // Check if already connected
            $existingRelation = \DB::connection('mongodb')
                ->table('user_groups')
                ->where('user_id', $user->id)
                ->where('group_id', $group->id)
                ->first();
            
            if ($existingRelation) {
                // Update verification status
                \DB::connection('mongodb')->table('user_groups')
                    ->where('user_id', $user->id)
                    ->where('group_id', $group->id)
                    ->update([
                        'is_admin' => true,
                        'last_verified' => now(),
                        'updated_at' => now()
                    ]);
                
                return response()->json([
                    'message' => 'Group already added and admin status verified',
                    'group' => $group
                ]);
            }
            
            // Add relationship
            \DB::connection('mongodb')->table('user_groups')->insert([
                'user_id' => $user->id,
                'group_id' => $group->id,
                'is_admin' => true,
                'permissions' => ['can_post_messages', 'can_edit_messages'],
                'added_at' => now(),
                'last_verified' => now(),
                'created_at' => now(),
                'updated_at' => now()
            ]);
            
            $user->incrementGroupCount();
            
            return response()->json([
                'message' => 'Group added successfully',
                'group' => $group
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Manual group addition failed', [
                'user_id' => $user->id,
                'group_id' => $request->group_id,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'error' => 'Failed to add group',
                'message' => 'Could not add the group. Please check that the bot is added as an admin.'
            ], 500);
        }
    }

    public function sync(Request $request)
    {
        $user = $request->user();
        
        // Check if this is a manual add request (when called via sync endpoint)
        if ($request->has('group_identifier')) {
            return $this->handleManualAdd($request, $user);
        }
        
        // Regular sync logic - discover groups the bot is in
        try {
            \Log::info('Starting group discovery for user', ['user_id' => $user->id]);
            
            // First verify existing admin statuses
            $verificationResult = $this->telegramService->verifyUserAdminStatusForAllGroups($user);
            
            // Get chats the bot is in
            $botChats = $this->telegramService->getBotChats();
            
            \Log::info('Found bot chats', [
                'count' => count($botChats),
                'chats' => array_map(function($chat) {
                    return [
                        'id' => $chat['id'],
                        'title' => $chat['title'],
                        'username' => $chat['username'] ?? null,
                        'type' => $chat['type']
                    ];
                }, $botChats)
            ]);
            
            $discoveredGroups = [];
            $addedGroups = 0;
            
            foreach ($botChats as $chat) {
                try {
                    // Check if user is admin in this group
                    $isUserAdmin = $this->telegramService->checkUserIsAdmin(
                        $chat['id'],
                        $user->telegram_id
                    );
                    
                    if ($isUserAdmin) {
                        \Log::info('User is admin in group', [
                            'chat_id' => $chat['id'],
                            'title' => $chat['title']
                        ]);
                        
                        // Check if user can add more groups
                        if (!$user->canAddGroup()) {
                            \Log::info('User reached group limit', [
                                'current_count' => $user->usage['groups_count']
                            ]);
                            break;
                        }
                        
                        // Get member count safely
                        $memberCount = 0;
                        try {
                            $memberCount = $this->telegramService->getChatMemberCount($chat['id']);
                        } catch (\Exception $e) {
                            \Log::warning('Could not get member count during sync', [
                                'chat_id' => $chat['id'],
                                'error' => $e->getMessage()
                            ]);
                            // Try to get from chat data if available
                            if (isset($chat['approximate_member_count'])) {
                                $memberCount = $chat['approximate_member_count'];
                            } elseif (isset($chat['member_count'])) {
                                $memberCount = $chat['member_count'];
                            }
                        }
                        
                        // Create or update group
                        $group = Group::updateOrCreate(
                            ['telegram_id' => (string)$chat['id']],
                            [
                                'title' => $chat['title'],
                                'username' => $chat['username'] ?? null,
                                'type' => $chat['type'],
                                'photo_url' => null,
                                'member_count' => $memberCount,
                                'updated_at' => now()
                            ]
                        );
                        
                        // Check if relationship already exists
                        $existingRelation = \DB::connection('mongodb')
                            ->table('user_groups')
                            ->where('user_id', $user->id)
                            ->where('group_id', $group->id)
                            ->first();
                        
                        if (!$existingRelation) {
                            // Add relationship
                            \DB::connection('mongodb')->table('user_groups')->insert([
                                'user_id' => $user->id,
                                'group_id' => $group->id,
                                'is_admin' => true,
                                'permissions' => ['can_post_messages', 'can_edit_messages'],
                                'added_at' => now(),
                                'last_verified' => now(),
                                'created_at' => now(),
                                'updated_at' => now()
                            ]);
                            
                            $user->incrementGroupCount();
                            $addedGroups++;
                            
                            \Log::info('Added new group relationship', [
                                'group_id' => $group->id,
                                'group_title' => $group->title
                            ]);
                        } else {
                            // Update verification time and ensure admin status is true
                            \DB::connection('mongodb')->table('user_groups')
                                ->where('user_id', $user->id)
                                ->where('group_id', $group->id)
                                ->update([
                                    'is_admin' => true,
                                    'last_verified' => now(),
                                    'updated_at' => now()
                                ]);
                        }
                        
                        $discoveredGroups[] = [
                            'id' => $group->id,
                            'title' => $group->title,
                            'username' => $group->username,
                            'newly_added' => !$existingRelation
                        ];
                    }
                } catch (\Exception $e) {
                    \Log::error('Error processing chat', [
                        'chat_id' => $chat['id'],
                        'error' => $e->getMessage()
                    ]);
                    continue;
                }
            }
            
            return response()->json([
                'message' => $addedGroups > 0 
                    ? "Successfully discovered and added {$addedGroups} new groups!" 
                    : "No new groups found. Make sure the bot is added as admin to your groups.",
                'discovered_groups' => $discoveredGroups,
                'added_count' => $addedGroups,
                'verification_result' => $verificationResult,
                'total_chats_checked' => count($botChats),
                'instructions' => [
                    'Add bot (@' . config('services.telegram.bot_username') . ') as admin to your Telegram groups',
                    'Run sync again to discover new groups',
                    'Use "Add Group" form if sync doesn\'t find your groups'
                ]
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Group sync failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'error' => 'Sync failed',
                'message' => 'Failed to sync groups. Please try again or use the manual add form.',
                'instructions' => [
                    'Make sure the bot is added as admin to your groups',
                    'Try using the "Add Group" form with the group @username'
                ]
            ], 500);
        }
    }

    public function checkAdminStatus(Request $request, $groupId)
    {
        \Log::info('Check admin status called', [
            'group_id' => $groupId,
            'user_id' => $request->user()->id
        ]);
        
        $user = $request->user();
        
        try {
            // Find the group
            $group = Group::where('_id', $groupId)
                        ->orWhere('id', $groupId)
                        ->first();
            
            if (!$group) {
                \Log::error('Group not found', ['group_id' => $groupId]);
                return response()->json([
                    'error' => 'Group not found',
                    'message' => 'The specified group could not be found.'
                ], 404);
            }
            
            \Log::info('Group found', [
                'group_id' => $group->id,
                'group_title' => $group->title,
                'telegram_id' => $group->telegram_id
            ]);
            
            // Use the enhanced verification method
            $isAdmin = $this->telegramService->verifyAndUpdateAdminStatus($user, $group);
            
            \Log::info('Enhanced admin verification result', [
                'user_id' => $user->id,
                'group_id' => $group->id,
                'is_admin' => $isAdmin
            ]);

            // Check current relationship status after verification
            $currentRelation = \DB::connection('mongodb')
                ->table('user_groups')
                ->where('user_id', $user->id)
                ->where('group_id', $group->id)
                ->first();

            if ($isAdmin && $currentRelation) {
                return response()->json([
                    'is_admin' => true,
                    'verified' => true,
                    'message' => 'Admin status verified and updated'
                ]);
            } else if ($isAdmin && !$currentRelation) {
                // Admin but relationship was created (if user had space)
                if ($user->canAddGroup()) {
                    return response()->json([
                        'is_admin' => true,
                        'newly_added' => true,
                        'message' => 'Group added successfully'
                    ]);
                } else {
                    $plan = $user->getSubscriptionPlan();
                    return response()->json([
                        'error' => 'Group limit reached',
                        'message' => "Your {$plan->display_name} plan allows up to {$plan->limits['groups']} groups. Please upgrade to add more groups.",
                        'is_admin' => true,
                        'can_add' => false,
                        'current_count' => $user->usage['groups_count'],
                        'limit' => $plan->limits['groups']
                    ], 403);
                }
            } else {
                // Not admin - relationship was removed during verification
                return response()->json([
                    'is_admin' => false,
                    'verified' => true,
                    'message' => 'You are not an admin in this group. Access has been revoked.'
                ]);
            }
            
        } catch (\Exception $e) {
            \Log::error('Error checking admin status', [
                'group_id' => $groupId,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'error' => 'Failed to check admin status',
                'message' => 'Unable to verify admin status. Please try again.'
            ], 500);
        }
    }

    public function addGroupManually(Request $request)
    {
        $user = $request->user();
        return $this->handleManualAdd($request, $user);
    }

    public function refreshGroupInfo(Request $request, $groupId)
    {
        $user = $request->user();
        
        try {
            // Find the group
            $group = Group::where('_id', $groupId)
                        ->orWhere('id', $groupId)
                        ->first();
            
            if (!$group) {
                return response()->json([
                    'error' => 'Group not found'
                ], 404);
            }
            
            // Verify user has access to this group
            $userGroup = \DB::connection('mongodb')
                ->table('user_groups')
                ->where('user_id', $user->id)
                ->where('group_id', $group->id)
                ->where('is_admin', true)
                ->first();
                
            if (!$userGroup) {
                return response()->json([
                    'error' => 'Not authorized'
                ], 403);
            }
            
            // Get fresh chat info from Telegram
            $chatInfo = $this->telegramService->getChatInfo($group->telegram_id);
            
            if (!$chatInfo) {
                return response()->json([
                    'error' => 'Could not fetch group information from Telegram'
                ], 400);
            }
            
            // Get member count
            $memberCount = 0;
            try {
                $memberCount = $this->telegramService->getChatMemberCount($group->telegram_id);
            } catch (\Exception $e) {
                \Log::warning('Could not refresh member count', [
                    'group_id' => $group->id,
                    'error' => $e->getMessage()
                ]);
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
            
            \Log::info('Group information refreshed', [
                'group_id' => $group->id,
                'title' => $group->title,
                'member_count' => $memberCount
            ]);
            
            return response()->json([
                'message' => 'Group information refreshed successfully',
                'group' => $group
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Failed to refresh group info', [
                'group_id' => $groupId,
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'error' => 'Failed to refresh group information',
                'message' => $e->getMessage()
            ], 500);
        }
    }
    public function removeGroup(Request $request, $groupId)
    {
        $user = $request->user();
        
        // Remove from user_groups relationship
        \DB::connection('mongodb')->table('user_groups')
            ->where('user_id', $user->id)
            ->where('group_id', $groupId)
            ->delete();
            
        $user->decrementGroupCount();

        return response()->json(['message' => 'Group removed successfully']);
    }

    private function handleManualAdd(Request $request, $user)
    {
        $request->validate([
            'group_identifier' => 'required|string',
        ]);
        
        if (!$user->canAddGroup()) {
            $plan = $user->getSubscriptionPlan();
            return response()->json([
                'error' => 'Group limit reached',
                'message' => "Your {$plan->display_name} plan allows up to {$plan->limits['groups']} groups."
            ], 403);
        }
        
        try {
            $originalIdentifier = trim($request->group_identifier);
            
            \Log::info('Attempting to add group manually', [
                'user_id' => $user->id,
                'original_identifier' => $originalIdentifier
            ]);
            
            // Prepare different formats to try
            $attempts = [];
            
            // If user provided @username, try it as is
            if (str_starts_with($originalIdentifier, '@')) {
                $attempts[] = $originalIdentifier; // @username
                $attempts[] = substr($originalIdentifier, 1); // username (without @)
            } else {
                // If user provided username without @, try both formats
                $attempts[] = '@' . $originalIdentifier; // @username
                $attempts[] = $originalIdentifier; // username
                
                // If it looks like a numeric ID, try with -100 prefix for supergroups
                if (is_numeric($originalIdentifier)) {
                    $attempts[] = '-100' . $originalIdentifier;
                }
            }
            
            \Log::info('Will try these identifiers', ['attempts' => $attempts]);
            
            // Try direct API calls to find the chat
            $chatInfo = null;
            foreach ($attempts as $attempt) {
                try {
                    \Log::info('Trying direct API call', ['attempt' => $attempt]);
                    $chatInfo = $this->telegramService->getChatInfo($attempt);
                    if ($chatInfo) {
                        \Log::info('Found chat with direct API call', [
                            'attempt' => $attempt,
                            'chat_title' => $chatInfo['title'] ?? 'unknown',
                            'chat_id' => $chatInfo['id'] ?? 'unknown'
                        ]);
                        break;
                    }
                } catch (\Exception $e) {
                    \Log::info('Failed API attempt', [
                        'attempt' => $attempt, 
                        'error' => $e->getMessage()
                    ]);
                    continue;
                }
            }
            
            if (!$chatInfo) {
                \Log::error('Could not find group with any identifier', [
                    'original_identifier' => $originalIdentifier,
                    'attempts' => $attempts
                ]);
                
                return response()->json([
                    'error' => 'Group not found',
                    'message' => 'Could not find the group. Please make sure: 1) The bot is added to the group as admin, 2) You entered the correct @username (like @mygroup), 3) The group is accessible to bots.',
                    'debug_info' => [
                        'tried_identifiers' => $attempts,
                        'suggestions' => [
                            'Make sure the bot is added as admin to the group',
                            'Use the exact @username format (like @mygroup)',
                            'Check that the group username is correct',
                            'Ensure the group allows bots to access it'
                        ]
                    ]
                ], 404);
            }
            
            // Check if user is admin
            \Log::info('Checking if user is admin', [
                'chat_id' => $chatInfo['id'],
                'user_telegram_id' => $user->telegram_id
            ]);
            
            $isUserAdmin = $this->telegramService->checkUserIsAdmin(
                $chatInfo['id'],
                $user->telegram_id
            );
            
            if (!$isUserAdmin) {
                \Log::warning('User is not admin in group', [
                    'chat_id' => $chatInfo['id'],
                    'user_telegram_id' => $user->telegram_id
                ]);
                
                return response()->json([
                    'error' => 'Not authorized',
                    'message' => 'You must be an admin in this group to add it. Found the group but you don\'t have admin permissions.',
                    'group_info' => [
                        'title' => $chatInfo['title'],
                        'type' => $chatInfo['type']
                    ]
                ], 403);
            }
            
            // Get member count safely with better error handling
            $memberCount = 0;
            try {
                $memberCount = $this->telegramService->getChatMemberCount($chatInfo['id']);
                \Log::info('Successfully got member count for manual add', [
                    'chat_id' => $chatInfo['id'],
                    'member_count' => $memberCount
                ]);
            } catch (\Exception $e) {
                \Log::warning('Could not get member count for manual add', [
                    'chat_id' => $chatInfo['id'],
                    'error' => $e->getMessage()
                ]);
                // Try to get approximate member count from chat info if available
                if (isset($chatInfo['approximate_member_count'])) {
                    $memberCount = $chatInfo['approximate_member_count'];
                } elseif (isset($chatInfo['member_count'])) {
                    $memberCount = $chatInfo['member_count'];
                }
            }
            
            // Create or update group
            $group = Group::updateOrCreate(
                ['telegram_id' => (string)$chatInfo['id']],
                [
                    'title' => $chatInfo['title'],
                    'username' => isset($chatInfo['username']) ? $chatInfo['username'] : null,
                    'type' => $chatInfo['type'],
                    'photo_url' => null,
                    'member_count' => $memberCount,
                    'updated_at' => now() // Force update timestamp
                ]
            );
            
            \Log::info('Group created/updated', [
                'group_id' => $group->id,
                'telegram_id' => $group->telegram_id,
                'title' => $group->title
            ]);
            
            // Check if relationship already exists
            $existingRelation = \DB::connection('mongodb')
                ->table('user_groups')
                ->where('user_id', $user->id)
                ->where('group_id', $group->id)
                ->first();
            
            if ($existingRelation) {
                // Update admin status and verification time
                \DB::connection('mongodb')->table('user_groups')
                    ->where('user_id', $user->id)
                    ->where('group_id', $group->id)
                    ->update([
                        'is_admin' => true,
                        'last_verified' => now(),
                        'updated_at' => now()
                    ]);
                
                \Log::info('Group relationship already exists, updated verification', [
                    'user_id' => $user->id,
                    'group_id' => $group->id
                ]);
                
                return response()->json([
                    'message' => 'Group already added to your account and admin status verified',
                    'group' => $group
                ]);
            }
            
            // Add relationship
            \DB::connection('mongodb')->table('user_groups')->insert([
                'user_id' => $user->id,
                'group_id' => $group->id,
                'is_admin' => true,
                'permissions' => ['can_post_messages', 'can_edit_messages'],
                'added_at' => now(),
                'last_verified' => now(),
                'created_at' => now(),
                'updated_at' => now()
            ]);
            
            $user->incrementGroupCount();
            
            \Log::info('Group added manually successfully', [
                'user_id' => $user->id,
                'group_id' => $group->id,
                'group_title' => $group->title
            ]);
            
            return response()->json([
                'message' => 'Group added successfully!',
                'group' => $group
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Manual group addition failed', [
                'user_id' => $user->id,
                'group_identifier' => $request->group_identifier,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'error' => 'Failed to add group',
                'message' => 'Could not add the group. Error: ' . $e->getMessage()
            ], 500);
        }
    }
}