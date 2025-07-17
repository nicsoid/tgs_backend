<?php
// app/Http/Middleware/VerifyGroupAdmin.php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Group;
use App\Models\ScheduledPost;

class VerifyGroupAdmin
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Get group IDs from the request
        $groupIds = $this->getGroupIdsFromRequest($request);

        if (empty($groupIds)) {
            return response()->json([
                'error' => 'No groups specified',
                'message' => 'Please select at least one group.'
            ], 422);
        }

        // Verify user is admin in all specified groups
        $userGroupIds = DB::connection('mongodb')
            ->table('user_groups')
            ->where('user_id', $user->id)
            ->where('is_admin', true)
            ->pluck('group_id')
            ->toArray();

        $unauthorizedGroups = [];
        $groups = Group::whereIn('_id', $groupIds)->get();
        
        foreach ($groups as $group) {
            $groupId = $group->id ?? $group->_id;
            if (!in_array($groupId, $userGroupIds)) {
                $unauthorizedGroups[] = $group->title;
            }
        }

        if (!empty($unauthorizedGroups)) {
            return response()->json([
                'error' => 'Not authorized for all groups',
                'message' => 'You are not an admin in these groups: ' . implode(', ', $unauthorizedGroups),
                'unauthorized_groups' => $unauthorizedGroups
            ], 403);
        }

        return $next($request);
    }

    /**
     * Extract group IDs from the request based on the route and method
     */
    private function getGroupIdsFromRequest(Request $request)
    {
        $groupIds = [];
        
        // For POST requests (creating new posts)
        if ($request->isMethod('POST')) {
            $groupIds = $request->input('group_ids', []);
        }
        
        // For PUT/PATCH requests (updating existing posts)
        elseif ($request->isMethod('PUT') || $request->isMethod('PATCH')) {
            // First check if group_ids are being updated
            if ($request->has('group_ids')) {
                $groupIds = $request->input('group_ids', []);
            } else {
                // If no group_ids in request, get them from existing post
                $postId = $request->route('id');
                if ($postId) {
                    $existingPost = ScheduledPost::where('user_id', $request->user()->id)
                        ->where(function($query) use ($postId) {
                            $query->where('_id', $postId)->orWhere('id', $postId);
                        })
                        ->first();
                        
                    if ($existingPost) {
                        $groupIds = $existingPost->group_ids ?? [];
                    }
                }
            }
        }

        // Ensure it's an array and remove any empty values
        if (!is_array($groupIds)) {
            $groupIds = [];
        }

        return array_filter($groupIds);
    }
}