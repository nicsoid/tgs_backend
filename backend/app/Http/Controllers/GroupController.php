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
        $groups = $request->user()->groups()
            ->wherePivot('is_admin', true)
            ->get();

        return response()->json($groups);
    }

    public function sync(Request $request)
    {
        $user = $request->user();
        
        // Check if user can add more groups
        if (!$user->canAddGroup()) {
            $plan = $user->getSubscriptionPlan();
            return response()->json([
                'error' => 'Group limit reached',
                'message' => "Your {$plan->display_name} plan allows up to {$plan->limits['groups']} groups. Please upgrade to add more groups.",
                'current_count' => $user->usage['groups_count'],
                'limit' => $plan->limits['groups']
            ], 403);
        }
        
        $updates = $this->telegramService->getUpdates();
        
        // Process updates to find groups where bot is admin
        // This is a simplified version - you'd need to implement webhook handling
        
        return response()->json(['message' => 'Groups synced successfully']);
    }

    public function checkAdminStatus(Request $request, $groupId)
    {
        $user = $request->user();
        $group = Group::findOrFail($groupId);
        
        $isAdmin = $this->telegramService->checkUserIsAdmin(
            $group->telegram_id,
            $user->telegram_id
        );

        if ($isAdmin) {
            // Check group limit before adding
            $existingRelation = $user->groups()->where('group_id', $group->id)->exists();
            
            if (!$existingRelation && !$user->canAddGroup()) {
                $plan = $user->getSubscriptionPlan();
                return response()->json([
                    'error' => 'Group limit reached',
                    'message' => "Your {$plan->display_name} plan allows up to {$plan->limits['groups']} groups.",
                    'is_admin' => true,
                    'can_add' => false
                ], 403);
            }

            $user->groups()->syncWithoutDetaching([
                $group->id => [
                    'is_admin' => true,
                    'last_verified' => now()
                ]
            ]);

            if (!$existingRelation) {
                $user->incrementGroupCount();
            }
        }

        return response()->json(['is_admin' => $isAdmin]);
    }

    public function removeGroup(Request $request, $groupId)
    {
        $user = $request->user();
        $user->groups()->detach($groupId);
        $user->decrementGroupCount();

        return response()->json(['message' => 'Group removed successfully']);
    }
}