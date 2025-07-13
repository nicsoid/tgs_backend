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
        
        \Log::info('=== DEBUGGING GROUP FETCH ===', [
            'user_id' => $user->id,
            'user_id_type' => gettype($user->id)
        ]);
        
        try {
            // Step 1: Check user_groups relationships
            $userGroupRelations = \DB::connection('mongodb')
                ->table('user_groups')
                ->where('user_id', $user->id)
                ->get();
            
            \Log::info('Step 1 - User group relations', [
                'query_user_id' => $user->id,
                'relations_found' => $userGroupRelations->count(),
                'raw_relations' => $userGroupRelations->toArray()
            ]);
            
            if ($userGroupRelations->isEmpty()) {
                \Log::error('No relationships found for user!');
                return response()->json([]);
            }
            
            // Step 2: Extract group IDs
            $groupIds = $userGroupRelations->pluck('group_id')->toArray();
            
            \Log::info('Step 2 - Group IDs extracted', [
                'group_ids' => $groupIds,
                'group_ids_types' => array_map('gettype', $groupIds)
            ]);
            
            // Step 3: Try different approaches to find groups
            
            // Approach A: Direct _id lookup
            $groupsA = \App\Models\Group::whereIn('_id', $groupIds)->get();
            \Log::info('Approach A - Direct _id lookup', [
                'count' => $groupsA->count(),
                'groups' => $groupsA->map(function($group) {
                    return [
                        '_id' => $group->_id,
                        'id' => $group->id ?? 'not set',
                        'title' => $group->title
                    ];
                })
            ]);
            
            // Approach B: Try with string conversion
            $stringGroupIds = array_map('strval', $groupIds);
            $groupsB = \App\Models\Group::whereIn('_id', $stringGroupIds)->get();
            \Log::info('Approach B - String converted IDs', [
                'string_ids' => $stringGroupIds,
                'count' => $groupsB->count()
            ]);
            
            // Approach C: Try individual lookups
            $groupsC = collect();
            foreach ($groupIds as $groupId) {
                $group = \App\Models\Group::where('_id', $groupId)->first();
                if ($group) {
                    $groupsC->push($group);
                }
                \Log::info('Approach C - Individual lookup', [
                    'looking_for' => $groupId,
                    'found' => $group ? 'yes' : 'no',
                    'group_title' => $group->title ?? 'not found'
                ]);
            }
            
            // Approach D: Check all groups and compare IDs
            $allGroups = \App\Models\Group::all();
            \Log::info('Approach D - All groups in database', [
                'total_groups' => $allGroups->count(),
                'all_group_ids' => $allGroups->pluck('_id')->toArray(),
                'looking_for_ids' => $groupIds
            ]);
            
            // Find which approach worked
            if ($groupsA->isNotEmpty()) {
                $finalGroups = $groupsA;
                \Log::info('Using Approach A results');
            } elseif ($groupsB->isNotEmpty()) {
                $finalGroups = $groupsB;
                \Log::info('Using Approach B results');
            } elseif ($groupsC->isNotEmpty()) {
                $finalGroups = $groupsC;
                \Log::info('Using Approach C results');
            } else {
                \Log::error('NO APPROACH WORKED!');
                
                // Manual comparison
                foreach ($allGroups as $group) {
                    foreach ($groupIds as $targetId) {
                        $match = false;
                        if ($group->_id == $targetId) $match = 'exact_match';
                        elseif ((string)$group->_id === (string)$targetId) $match = 'string_match';
                        elseif ($group->id == $targetId) $match = 'id_match';
                        
                        if ($match) {
                            \Log::info('MANUAL MATCH FOUND', [
                                'group_id' => $group->_id,
                                'target_id' => $targetId,
                                'match_type' => $match,
                                'group_title' => $group->title
                            ]);
                        }
                    }
                }
                
                return response()->json([]);
            }
            
            // Ensure proper ID format
            $finalGroups = $finalGroups->map(function($group) {
                if (!isset($group->id)) {
                    $group->id = (string)$group->_id;
                }
                return $group;
            });
            
            \Log::info('=== FINAL RESULT ===', [
                'groups_count' => $finalGroups->count(),
                'groups' => $finalGroups->map(function($group) {
                    return [
                        'id' => $group->id,
                        '_id' => $group->_id,
                        'title' => $group->title
                    ];
                })
            ]);
            
            return response()->json($finalGroups);
            
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
            
            // Create or update group
            $memberCount = $this->telegramService->getChatMemberCount($chatInfo['id']);
            
            $group = Group::updateOrCreate(
                ['telegram_id' => (string)$chatInfo['id']],
                [
                    'title' => $chatInfo['title'],
                    'username' => $chatInfo['username'] ?? null,
                    'type' => $chatInfo['type'],
                    'photo_url' => null,
                    'member_count' => $memberCount
                ]
            );
            
            // Check if already connected
            $existingRelation = $user->groups()->where('group_id', $group->id)->exists();
            
            if ($existingRelation) {
                return response()->json([
                    'message' => 'Group already added',
                    'group' => $group
                ]);
            }
            
            // Add relationship
            // $user->groups()->attach($group->id, [
            //     'is_admin' => true,
            //     'permissions' => ['can_post_messages', 'can_edit_messages'],
            //     'added_at' => now(),
            //     'last_verified' => now()
            // ]);
            $user->groups()->syncWithoutDetaching([
                $group->id => [
                    'is_admin' => true,
                    'permissions' => ['can_post_messages', 'can_edit_messages'],
                    'added_at' => now(),
                    'last_verified' => now()
                ]
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

    private function addGroupToUser($user, $group)
    {
        // Don't store user_id in the group document!
        // Instead, create proper relationship
        
        \DB::connection('mongodb')->table('user_groups')->updateOrInsert([
            'user_id' => $user->id,
            'group_id' => $group->id
        ], [
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
                        
                        // Create or update group
                        $group = Group::updateOrCreate(
                            ['telegram_id' => (string)$chat['id']],
                            [
                                'title' => $chat['title'],
                                'username' => $chat['username'] ?? null,
                                'type' => $chat['type'],
                                'photo_url' => null,
                                'member_count' => 0
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
                            // Update verification time
                            \DB::connection('mongodb')->table('user_groups')
                                ->where('user_id', $user->id)
                                ->where('group_id', $group->id)
                                ->update(['last_verified' => now()]);
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
            
            // Check if user already has this group - FIXED COLLECTION ACCESS
            $existingRelation = \DB::connection('mongodb')
                ->table('user_groups')  // Use ->table() instead of ->collection()
                ->where('user_id', $user->id)
                ->where('group_id', $group->id)
                ->first();
            
            \Log::info('Existing relationship check', [
                'exists' => $existingRelation ? 'yes' : 'no',
                'relationship_data' => $existingRelation
            ]);
            
            // Check admin status with Telegram
            $isAdmin = $this->telegramService->checkUserIsAdmin(
                $group->telegram_id,
                $user->telegram_id
            );
            
            \Log::info('Telegram admin check result', [
                'is_admin' => $isAdmin
            ]);

            if ($isAdmin) {
                if ($existingRelation) {
                    // Group already exists - just update verification time
                    \DB::connection('mongodb')->table('user_groups')
                        ->where('user_id', $user->id)
                        ->where('group_id', $group->id)
                        ->update([
                            'last_verified' => now(),
                            'is_admin' => true,
                            'updated_at' => now()
                        ]);
                    
                    \Log::info('Updated existing group relationship verification');
                    
                    return response()->json([
                        'is_admin' => true,
                        'already_added' => true,
                        'message' => 'Admin status verified and updated'
                    ]);
                } else {
                    // New group - check if user can add more groups
                    if (!$user->canAddGroup()) {
                        $plan = $user->getSubscriptionPlan();
                        
                        \Log::info('Group limit reached', [
                            'current_count' => $user->usage['groups_count'],
                            'limit' => $plan->limits['groups']
                        ]);
                        
                        return response()->json([
                            'error' => 'Group limit reached',
                            'message' => "Your {$plan->display_name} plan allows up to {$plan->limits['groups']} groups. Please upgrade to add more groups.",
                            'is_admin' => true,
                            'can_add' => false,
                            'current_count' => $user->usage['groups_count'],
                            'limit' => $plan->limits['groups']
                        ], 403);
                    }

                    // Add new group relationship
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
                    
                    \Log::info('Added new group relationship');
                    
                    return response()->json([
                        'is_admin' => true,
                        'newly_added' => true,
                        'message' => 'Group added successfully'
                    ]);
                }
            } else {
                // Not admin
                \Log::info('User is not admin in this group');
                
                return response()->json([
                    'is_admin' => false,
                    'message' => 'You are not an admin in this group'
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

    public function removeGroup(Request $request, $groupId)
    {
        $user = $request->user();
        $user->groups()->detach($groupId);
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
            
            // Get member count safely
            try {
                $memberCount = $this->telegramService->getChatMemberCount($chatInfo['id']);
            } catch (\Exception $e) {
                \Log::warning('Could not get member count', ['error' => $e->getMessage()]);
                $memberCount = 0;
            }
            
            // Create or update group
            $group = Group::updateOrCreate(
                ['telegram_id' => (string)$chatInfo['id']],
                [
                    'title' => $chatInfo['title'],
                    'username' => isset($chatInfo['username']) ? $chatInfo['username'] : null,
                    'type' => $chatInfo['type'],
                    'photo_url' => null,
                    'member_count' => $memberCount
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
                \Log::info('Group relationship already exists', [
                    'user_id' => $user->id,
                    'group_id' => $group->id
                ]);
                
                return response()->json([
                    'message' => 'Group already added to your account',
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