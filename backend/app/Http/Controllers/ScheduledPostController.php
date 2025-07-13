<?php
// app/Http/Controllers/ScheduledPostController.php

namespace App\Http\Controllers;

use App\Models\ScheduledPost;
use App\Models\Group;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ScheduledPostController extends Controller
{
    public function index(Request $request)
    {
        $posts = $request->user()->scheduledPosts()
            ->with(['group', 'logs'])
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json($posts);
    }

    public function store(Request $request)
    {
        // CUSTOM VALIDATION for MongoDB group_id
        $request->validate([
            'group_id' => 'required|string',
            'text' => 'required|string',
            'schedule_times' => 'required|array|min:1',
            'schedule_times.*' => 'required|date|after:now',
            'media' => 'array',
            'media.*' => 'file|mimes:jpeg,png,jpg,gif,mp4,avi,mov|max:50000',
            'advertiser_username' => 'required|string',
            'amount_paid' => 'required|numeric|min:0',
            'currency' => 'required|string|size:3'
        ]);

        $user = $request->user();
        
        // CUSTOM group validation for MongoDB
        $group = Group::where('_id', $request->group_id)
                    ->orWhere('id', $request->group_id)
                    ->first();
        
        if (!$group) {
            return response()->json([
                'error' => 'Invalid group',
                'message' => 'The selected group does not exist.',
                'debug' => [
                    'provided_group_id' => $request->group_id,
                    'group_id_type' => gettype($request->group_id)
                ]
            ], 422);
        }
        
        \Log::info('Group validation passed', [
            'provided_id' => $request->group_id,
            'found_group' => [
                'id' => $group->id,
                '_id' => $group->_id,
                'title' => $group->title
            ]
        ]);
        
        // Check message limit
        $scheduleCount = count($request->schedule_times);
        $plan = $user->getSubscriptionPlan();
        
        // Check if user has enough message quota
        $user->checkAndResetMonthlyUsage();
        $remainingMessages = $plan->limits['messages_per_month'] - $user->usage['messages_sent_this_month'];
        
        if ($scheduleCount > $remainingMessages) {
            return response()->json([
                'error' => 'Message limit exceeded',
                'message' => "Your {$plan->display_name} plan allows {$plan->limits['messages_per_month']} messages per month. You have {$remainingMessages} remaining.",
                'remaining_messages' => $remainingMessages,
                'requested_messages' => $scheduleCount
            ], 403);
        }
        
        // Verify user is admin in this group
        $isAdmin = \DB::connection('mongodb')
            ->table('user_groups')
            ->where('user_id', $user->id)
            ->where('group_id', $group->id) // Use the found group's ID
            ->where('is_admin', true)
            ->exists();

        if (!$isAdmin) {
            \Log::error('User not admin in group', [
                'user_id' => $user->id,
                'group_id' => $group->id,
                'relationships' => \DB::connection('mongodb')
                    ->table('user_groups')
                    ->where('user_id', $user->id)
                    ->get()
            ]);
            
            return response()->json([
                'error' => 'Not authorized', 
                'message' => 'You are not an admin of this group'
            ], 403);
        }

        // Handle media uploads
        $mediaData = [];
        if ($request->hasFile('media')) {
            foreach ($request->file('media') as $file) {
                $path = $file->store('media', 'public');
                $mediaData[] = [
                    'type' => str_starts_with($file->getMimeType(), 'video/') ? 'video' : 'photo',
                    'url' => Storage::url($path),
                    'thumbnail' => null // Generate thumbnail for videos
                ];
            }
        }

        $post = ScheduledPost::create([
            'user_id' => $user->id,
            'group_id' => $group->id, // Use the found group's ID
            'content' => [
                'text' => $request->text,
                'media' => $mediaData
            ],
            'schedule_times' => $request->schedule_times,
            'user_timezone' => $user->getTimezone(),
            'advertiser' => [
                'telegram_username' => $request->advertiser_username,
                'amount_paid' => $request->amount_paid,
                'currency' => $request->currency
            ],
            'status' => 'pending'
        ]);

        // Update user's message count
        for ($i = 0; $i < $scheduleCount; $i++) {
            $user->incrementMessageCount();
        }

        \Log::info('Post created successfully', [
            'post_id' => $post->id,
            'user_id' => $user->id,
            'group_id' => $group->id,
            'schedule_count' => $scheduleCount
        ]);

        return response()->json($post, 201);
    }

    public function update(Request $request, $id)
    {
        $post = ScheduledPost::where('user_id', $request->user()->id)
            ->findOrFail($id);

        if ($post->status !== 'pending') {
            return response()->json(['error' => 'Cannot update post that has started sending'], 400);
        }

        $request->validate([
            'text' => 'string',
            'schedule_times' => 'array|min:1',
            'schedule_times.*' => 'date|after:now',
            'advertiser_username' => 'string',
            'amount_paid' => 'numeric|min:0',
            'currency' => 'string|size:3'
        ]);

        // Check if schedule times are being changed
        if ($request->has('schedule_times')) {
            $oldCount = count($post->schedule_times);
            $newCount = count($request->schedule_times);
            $difference = $newCount - $oldCount;
            
            if ($difference > 0) {
                // Check if user has quota for additional messages
                $user = $request->user();
                $plan = $user->getSubscriptionPlan();
                $remainingMessages = $plan->limits['messages_per_month'] - $user->usage['messages_sent_this_month'];
                
                if ($difference > $remainingMessages) {
                    return response()->json([
                        'error' => 'Message limit exceeded',
                        'message' => "You need {$difference} more messages but only have {$remainingMessages} remaining.",
                        'remaining_messages' => $remainingMessages
                    ], 403);
                }
                
                // Update message count
                for ($i = 0; $i < $difference; $i++) {
                    $user->incrementMessageCount();
                }
            }
        }

        // Update post fields
        if ($request->has('text')) {
            $post->content = array_merge($post->content, ['text' => $request->text]);
        }
        
        if ($request->has('schedule_times')) {
            $post->schedule_times = $request->schedule_times;
            // Recalculate UTC times
            $post->schedule_times_utc = collect($request->schedule_times)
                ->map(function ($time) use ($post) {
                    return \Carbon\Carbon::parse($time, $post->user_timezone)
                        ->setTimezone('UTC')
                        ->toDateTimeString();
                })
                ->toArray();
            $post->total_scheduled = count($request->schedule_times);
        }

        if ($request->has('advertiser_username') || $request->has('amount_paid')) {
            $post->advertiser = array_merge($post->advertiser, $request->only(['telegram_username', 'amount_paid', 'currency']));
        }

        $post->save();

        return response()->json($post);
    }

    public function destroy(Request $request, $id)
    {
        $post = ScheduledPost::where('user_id', $request->user()->id)
            ->findOrFail($id);

        if ($post->status !== 'pending') {
            return response()->json(['error' => 'Cannot delete post that has started sending'], 400);
        }

        $post->delete();

        return response()->json(['message' => 'Post deleted successfully']);
    }

    public function getUsageStats(Request $request)
    {
        $user = $request->user();
        $plan = $user->getSubscriptionPlan();
        $user->checkAndResetMonthlyUsage();

        return response()->json([
            'plan' => $plan,
            'usage' => [
                'groups' => [
                    'used' => $user->usage['groups_count'],
                    'limit' => $plan->limits['groups'],
                    'percentage' => round(($user->usage['groups_count'] / $plan->limits['groups']) * 100, 2)
                ],
                'messages' => [
                    'used' => $user->usage['messages_sent_this_month'],
                    'limit' => $plan->limits['messages_per_month'],
                    'percentage' => round(($user->usage['messages_sent_this_month'] / $plan->limits['messages_per_month']) * 100, 2)
                ]
            ],
            'subscription' => $user->subscription
        ]);
    }
}