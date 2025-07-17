<?php
// app/Http/Controllers/ScheduledPostController.php

namespace App\Http\Controllers;

use App\Models\ScheduledPost;
use App\Models\Group;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ScheduledPostController extends Controller
{
    public function index(Request $request)
    {
        $posts = $request->user()->scheduledPosts()
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        // Load groups for each post
        foreach ($posts as $post) {
            $post->groups_data = $post->groups;
        }

        return response()->json($posts);
    }

    public function show(Request $request, $id)
    {
        $post = ScheduledPost::where('user_id', $request->user()->id)
            ->where(function($query) use ($id) {
                $query->where('_id', $id)->orWhere('id', $id);
            })
            ->first();

        if (!$post) {
            return response()->json([
                'error' => 'Post not found'
            ], 404);
        }

        // Load groups data
        $post->groups_data = $post->groups;

        return response()->json($post);
    }

    public function store(Request $request)
    {
        // Validation for multiple groups
        $request->validate([
            'group_ids' => 'required|array|min:1',
            'group_ids.*' => 'required|string|distinct',
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
        
        // Validate all groups exist (middleware already verified admin access)
        $groupIds = array_unique($request->group_ids);
        $groups = Group::whereIn('_id', $groupIds)->get();
        
        if ($groups->count() !== count($groupIds)) {
            return response()->json([
                'error' => 'Invalid groups',
                'message' => 'One or more selected groups do not exist.',
            ], 422);
        }
        
        // NOTE: Admin verification is now handled by middleware
        
        // Check message limit (groups * schedule times)
        $scheduleCount = count($request->schedule_times);
        $groupCount = count($groupIds);
        $totalMessages = $scheduleCount * $groupCount;
        
        $plan = $user->getSubscriptionPlan();
        $user->checkAndResetMonthlyUsage();
        $remainingMessages = $plan->limits['messages_per_month'] - $user->usage['messages_sent_this_month'];
        
        if ($totalMessages > $remainingMessages) {
            return response()->json([
                'error' => 'Message limit exceeded',
                'message' => "Your {$plan->display_name} plan allows {$plan->limits['messages_per_month']} messages per month. You have {$remainingMessages} remaining. This post would send {$totalMessages} messages ({$groupCount} groups × {$scheduleCount} times).",
                'remaining_messages' => $remainingMessages,
                'requested_messages' => $totalMessages
            ], 403);
        }

        // Handle media uploads
        $mediaData = [];
        if ($request->hasFile('media')) {
            foreach ($request->file('media') as $file) {
                try {
                    $filename = time() . '_' . Str::random(10) . '.' . $file->getClientOriginalExtension();
                    $path = $file->storeAs('media', $filename, 'public');
                    
                    if (Storage::disk('public')->exists($path)) {
                        $mediaData[] = [
                            'type' => str_starts_with($file->getMimeType(), 'video/') ? 'video' : 'photo',
                            'url' => Storage::url($path),
                            'path' => $path,
                            'original_name' => $file->getClientOriginalName(),
                            'size' => $file->getSize(),
                            'mime_type' => $file->getMimeType()
                        ];
                        
                        \Log::info('Media file stored successfully', [
                            'path' => $path,
                            'url' => Storage::url($path),
                            'size' => $file->getSize()
                        ]);
                    } else {
                        \Log::error('Failed to store media file', ['filename' => $filename]);
                    }
                } catch (\Exception $e) {
                    \Log::error('Error storing media file', [
                        'error' => $e->getMessage(),
                        'file' => $file->getClientOriginalName()
                    ]);
                }
            }
        }

        $post = ScheduledPost::create([
            'user_id' => $user->id,
            'group_ids' => $groupIds,
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
        for ($i = 0; $i < $totalMessages; $i++) {
            $user->incrementMessageCount();
        }

        \Log::info('Multi-group post created successfully', [
            'post_id' => $post->id,
            'user_id' => $user->id,
            'groups_count' => $groupCount,
            'schedule_count' => $scheduleCount,
            'total_messages' => $totalMessages
        ]);

        // Load groups data for response
        $post->groups_data = $post->groups;

        return response()->json($post, 201);
    }

    public function update(Request $request, $id)
    {
        $post = ScheduledPost::where('user_id', $request->user()->id)
            ->where(function($query) use ($id) {
                $query->where('_id', $id)->orWhere('id', $id);
            })
            ->first();

        if (!$post) {
            return response()->json(['error' => 'Post not found'], 404);
        }

        if ($post->status !== 'pending') {
            return response()->json([
                'error' => 'Cannot update post that has started sending'
            ], 400);
        }

        $request->validate([
            'group_ids' => 'array|min:1',
            'group_ids.*' => 'string|distinct',
            'text' => 'string',
            'schedule_times' => 'array|min:1',
            'schedule_times.*' => 'date|after:now',
            'advertiser_username' => 'string',
            'amount_paid' => 'numeric|min:0',
            'currency' => 'string|size:3',
            'media' => 'array',
            'media.*' => 'file|mimes:jpeg,png,jpg,gif,mp4,avi,mov|max:50000',
            'keep_existing_media' => 'array',
            'keep_existing_media.*' => 'integer'
        ]);

        // Check if groups or schedule times are being changed
        $oldGroupCount = count($post->group_ids ?? []);
        $oldScheduleCount = count($post->schedule_times ?? []);
        $oldTotalMessages = $oldGroupCount * $oldScheduleCount;

        $newGroupCount = $request->has('group_ids') ? count(array_unique($request->group_ids)) : $oldGroupCount;
        $newScheduleCount = $request->has('schedule_times') ? count($request->schedule_times) : $oldScheduleCount;
        $newTotalMessages = $newGroupCount * $newScheduleCount;

        $difference = $newTotalMessages - $oldTotalMessages;

        if ($difference > 0) {
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

        // Validate groups if being updated
        if ($request->has('group_ids')) {
            $groupIds = array_unique($request->group_ids);
            $groups = Group::whereIn('_id', $groupIds)->get();
            
            if ($groups->count() !== count($groupIds)) {
                return response()->json([
                    'error' => 'Invalid groups',
                    'message' => 'One or more selected groups do not exist.',
                ], 422);
            }

            $post->group_ids = $groupIds;
        }

        // Handle media updates
        if ($request->has('keep_existing_media') || $request->hasFile('media')) {
            $currentMedia = $post->content['media'] ?? [];
            $newMediaData = [];
            
            \Log::info('Media update request', [
                'current_media_count' => count($currentMedia),
                'keep_existing_media' => $request->keep_existing_media ?? [],
                'new_media_files' => $request->hasFile('media') ? count($request->file('media')) : 0
            ]);
            
            // Keep selected existing media
            if ($request->has('keep_existing_media')) {
                $keepIndices = $request->keep_existing_media;
                foreach ($keepIndices as $index) {
                    if (isset($currentMedia[$index])) {
                        $mediaItem = $currentMedia[$index];
                        
                        // Verify the file still exists
                        if (isset($mediaItem['path']) && Storage::disk('public')->exists($mediaItem['path'])) {
                            $newMediaData[] = $mediaItem;
                            \Log::info('Keeping existing media', ['index' => $index, 'path' => $mediaItem['path']]);
                        } else {
                            \Log::warning('Existing media file not found', ['index' => $index, 'path' => $mediaItem['path'] ?? 'no path']);
                        }
                    }
                }
            }
            
            // Delete media files that are not being kept
            if ($request->has('keep_existing_media')) {
                $keepIndices = $request->keep_existing_media;
                foreach ($currentMedia as $index => $mediaItem) {
                    if (!in_array($index, $keepIndices)) {
                        $this->deleteMediaFile($mediaItem);
                        \Log::info('Deleted media file', ['index' => $index]);
                    }
                }
            }
            
            // Add new media files
            if ($request->hasFile('media')) {
                foreach ($request->file('media') as $file) {
                    try {
                        $filename = time() . '_' . Str::random(10) . '.' . $file->getClientOriginalExtension();
                        $path = $file->storeAs('media', $filename, 'public');
                        
                        if (Storage::disk('public')->exists($path)) {
                            $newMediaData[] = [
                                'type' => str_starts_with($file->getMimeType(), 'video/') ? 'video' : 'photo',
                                'url' => Storage::url($path),
                                'path' => $path,
                                'original_name' => $file->getClientOriginalName(),
                                'size' => $file->getSize(),
                                'mime_type' => $file->getMimeType()
                            ];
                            
                            \Log::info('New media file stored', [
                                'path' => $path,
                                'url' => Storage::url($path)
                            ]);
                        }
                    } catch (\Exception $e) {
                        \Log::error('Error storing new media file', [
                            'error' => $e->getMessage(),
                            'file' => $file->getClientOriginalName()
                        ]);
                    }
                }
            }
            
            $content = $post->content;
            $content['media'] = $newMediaData;
            $post->content = $content;
            
            \Log::info('Media update completed', [
                'post_id' => $post->id,
                'final_media_count' => count($newMediaData)
            ]);
        }

        // Update other fields
        if ($request->has('text')) {
            $content = $post->content;
            $content['text'] = $request->text;
            $post->content = $content;
        }
        
        if ($request->has('schedule_times')) {
            $post->schedule_times = $request->schedule_times;
        }

        if ($request->has('advertiser_username') || $request->has('amount_paid') || $request->has('currency')) {
            $advertiserUpdate = [];
            if ($request->has('advertiser_username')) {
                $advertiserUpdate['telegram_username'] = $request->advertiser_username;
            }
            if ($request->has('amount_paid')) {
                $advertiserUpdate['amount_paid'] = $request->amount_paid;
            }
            if ($request->has('currency')) {
                $advertiserUpdate['currency'] = $request->currency;
            }
            $post->advertiser = array_merge($post->advertiser, $advertiserUpdate);
        }

        $post->save();

        // Load groups data for response
        $post->groups_data = $post->groups;

        return response()->json($post);
    }

    public function destroy(Request $request, $id)
    {
        $post = ScheduledPost::where('user_id', $request->user()->id)
            ->where(function($query) use ($id) {
                $query->where('_id', $id)->orWhere('id', $id);
            })
            ->first();

        if (!$post) {
            return response()->json(['error' => 'Post not found'], 404);
        }

        if ($post->status !== 'pending') {
            return response()->json([
                'error' => 'Cannot delete post that has started sending'
            ], 400);
        }

        // Delete associated media files
        if (isset($post->content['media'])) {
            foreach ($post->content['media'] as $mediaItem) {
                $this->deleteMediaFile($mediaItem);
            }
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

    /**
     * Debug endpoint to check media files
     */
    public function debugMedia(Request $request)
    {
        if (!app()->environment('local')) {
            return response()->json(['error' => 'Debug endpoint only available in local environment'], 403);
        }

        $storagePublicPath = storage_path('app/public');
        $mediaPath = $storagePublicPath . '/media';
        
        $info = [
            'storage_path' => $storagePublicPath,
            'media_path' => $mediaPath,
            'storage_exists' => is_dir($storagePublicPath),
            'media_exists' => is_dir($mediaPath),
            'storage_writable' => is_writable($storagePublicPath),
            'media_writable' => is_writable($mediaPath),
            'public_symlink' => is_link(public_path('storage')),
            'symlink_target' => is_link(public_path('storage')) ? readlink(public_path('storage')) : 'No symlink',
            'media_files' => [],
        ];

        // List media files if directory exists
        if (is_dir($mediaPath)) {
            $files = scandir($mediaPath);
            $info['media_files'] = array_filter($files, function($file) {
                return !in_array($file, ['.', '..']);
            });
        }

        return response()->json($info);
    }

    /**
     * Helper method to delete media files from storage
     */
    private function deleteMediaFile($mediaItem)
    {
        try {
            // Try to delete using the stored path first
            if (isset($mediaItem['path']) && Storage::disk('public')->exists($mediaItem['path'])) {
                Storage::disk('public')->delete($mediaItem['path']);
                \Log::info('Deleted media file using path', ['path' => $mediaItem['path']]);
                return;
            }
            
            // Fallback: extract path from URL
            if (isset($mediaItem['url'])) {
                $path = str_replace('/storage/', '', parse_url($mediaItem['url'], PHP_URL_PATH));
                
                if (Storage::disk('public')->exists($path)) {
                    Storage::disk('public')->delete($path);
                    \Log::info('Deleted media file using URL path', ['path' => $path]);
                } else {
                    \Log::warning('Media file not found for deletion', ['path' => $path]);
                }
            }
        } catch (\Exception $e) {
            \Log::error('Failed to delete media file', [
                'media_item' => $mediaItem,
                'error' => $e->getMessage()
            ]);
        }
    }
}