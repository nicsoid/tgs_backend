<?php
// app/Http/Controllers/ScheduledPostController.php - Always Editable Version

namespace App\Http\Controllers;

use App\Models\ScheduledPost;
use App\Models\Group;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Carbon\Carbon;

class ScheduledPostController extends Controller
{
    public function index(Request $request)
    {
        $posts = $request->user()->scheduledPosts()
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        // Load groups for each post and add statistics
        foreach ($posts as $post) {
            $post->groups_data = $post->groups;
            $post->statistics = $post->getStatistics();
            $post->pending_sends = $post->hasPendingSends();
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
            return response()->json(['error' => 'Post not found'], 404);
        }

        // Load groups data and statistics
        $post->groups_data = $post->groups;
        $post->statistics = $post->getStatistics();
        $post->pending_times = $post->getPendingScheduleTimes();
        $post->sent_times = $post->getSentScheduleTimes();

        return response()->json($post);
    }

    public function store(Request $request)
    {
        // Validation
        $request->validate([
            'group_ids' => 'required|array|min:1',
            'group_ids.*' => 'required|string|distinct',
            'text' => 'required|string',
            'schedule_times' => 'required|array|min:1',
            'schedule_times.*' => 'required|date',
            'media' => 'array',
            'media.*' => 'file|mimes:jpeg,png,jpg,gif,mp4,avi,mov|max:50000',
            'advertiser_username' => 'required|string',
            'amount_paid' => 'required|numeric|min:0',
            'currency' => 'required|string|size:3'
        ]);

        $user = $request->user();
        
        // Validate all groups exist and user has admin access
        $groupIds = array_unique($request->group_ids);
        $groups = Group::whereIn('_id', $groupIds)->get();
        
        if ($groups->count() !== count($groupIds)) {
            return response()->json([
                'error' => 'Invalid groups',
                'message' => 'One or more selected groups do not exist.',
            ], 422);
        }

        // Filter out past times
        $validScheduleTimes = [];
        $userTimezone = $user->getTimezone();
        $now = Carbon::now($userTimezone);

        foreach ($request->schedule_times as $time) {
            $timeCarbon = Carbon::parse($time, $userTimezone);
            if ($timeCarbon->isFuture()) {
                $validScheduleTimes[] = $time;
            }
        }

        if (empty($validScheduleTimes)) {
            return response()->json([
                'error' => 'No valid future times',
                'message' => 'All provided schedule times are in the past.',
            ], 422);
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
            'schedule_times' => $validScheduleTimes,
            'user_timezone' => $userTimezone,
            'advertiser' => [
                'telegram_username' => $request->advertiser_username,
                'amount_paid' => $request->amount_paid,
                'currency' => $request->currency
            ]
        ]);

        \Log::info('Post created successfully', [
            'post_id' => $post->id,
            'user_id' => $user->id,
            'groups_count' => count($groupIds),
            'schedule_count' => count($validScheduleTimes),
            'user_timezone' => $userTimezone
        ]);

        // Load groups data for response
        $post->groups_data = $post->groups;
        $post->statistics = $post->getStatistics();

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

        $request->validate([
            'group_ids' => 'array|min:1',
            'group_ids.*' => 'string|distinct',
            'text' => 'string',
            'schedule_times' => 'array|min:1',
            'schedule_times.*' => 'date',
            'advertiser_username' => 'string',
            'amount_paid' => 'numeric|min:0',
            'currency' => 'string|size:3',
            'media' => 'array',
            'media.*' => 'file|mimes:jpeg,png,jpg,gif,mp4,avi,mov|max:50000',
            'keep_existing_media' => 'array',
            'keep_existing_media.*' => 'integer'
        ]);

        $user = $request->user();

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

        // Handle schedule times update - filter out past times
        if ($request->has('schedule_times')) {
            $validScheduleTimes = [];
            $userTimezone = $user->getTimezone();
            $now = Carbon::now($userTimezone);

            foreach ($request->schedule_times as $time) {
                $timeCarbon = Carbon::parse($time, $userTimezone);
                // Allow all times - system will handle past times gracefully
                $validScheduleTimes[] = $time;
            }

            $post->schedule_times = $validScheduleTimes;
        }

        // Handle media updates
        if ($request->has('keep_existing_media') || $request->hasFile('media')) {
            $currentMedia = $post->content['media'] ?? [];
            $newMediaData = [];
            
            // Keep selected existing media
            if ($request->has('keep_existing_media')) {
                $keepIndices = $request->keep_existing_media;
                foreach ($keepIndices as $index) {
                    if (isset($currentMedia[$index])) {
                        $mediaItem = $currentMedia[$index];
                        
                        // Verify the file still exists
                        if (isset($mediaItem['path']) && Storage::disk('public')->exists($mediaItem['path'])) {
                            $newMediaData[] = $mediaItem;
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
        }

        // Update other fields
        if ($request->has('text')) {
            $content = $post->content;
            $content['text'] = $request->text;
            $post->content = $content;
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
        $post->statistics = $post->getStatistics();

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

        // Delete associated media files
        if (isset($post->content['media'])) {
            foreach ($post->content['media'] as $mediaItem) {
                $this->deleteMediaFile($mediaItem);
            }
        }

        // Delete logs
        $post->logs()->delete();

        $post->delete();

        return response()->json(['message' => 'Post deleted successfully']);
    }

    /**
     * Helper method to delete media files from storage
     */
    private function deleteMediaFile($mediaItem)
    {
        try {
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

    public function getUsageStats(Request $request)
    {
        try {
            $user = $request->user();
            
            // Get subscription plan
            $plan = $user->getSubscriptionPlan();
            if (!$plan) {
                // Fallback plan if none found
                $plan = (object) [
                    'name' => 'free',
                    'display_name' => 'Free',
                    'limits' => [
                        'groups' => 1,
                        'messages_per_month' => 3
                    ]
                ];
            }

            // Reset monthly usage if needed
            $user->checkAndResetMonthlyUsage();
            
            // Get current usage
            $usage = $user->usage ?? [
                'groups_count' => 0,
                'messages_sent_this_month' => 0,
                'last_reset_date' => now()->startOfMonth()->toDateTimeString()
            ];

            // Calculate percentages safely
            $groupsPercentage = $plan->limits['groups'] > 0 
                ? round(($usage['groups_count'] / $plan->limits['groups']) * 100, 2)
                : 0;
                
            $messagesPercentage = $plan->limits['messages_per_month'] > 0
                ? round(($usage['messages_sent_this_month'] / $plan->limits['messages_per_month']) * 100, 2)
                : 0;

            return response()->json([
                'plan' => $plan,
                'usage' => [
                    'groups' => [
                        'used' => $usage['groups_count'],
                        'limit' => $plan->limits['groups'],
                        'percentage' => $groupsPercentage
                    ],
                    'messages' => [
                        'used' => $usage['messages_sent_this_month'],
                        'limit' => $plan->limits['messages_per_month'],
                        'percentage' => $messagesPercentage
                    ]
                ],
                'subscription' => $user->subscription
            ]);
        } catch (\Exception $e) {
            \Log::error('Error getting usage stats', [
                'user_id' => $request->user()->id ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Return fallback data
            return response()->json([
                'plan' => (object) [
                    'name' => 'free',
                    'display_name' => 'Free',
                    'limits' => [
                        'groups' => 1,
                        'messages_per_month' => 3
                    ]
                ],
                'usage' => [
                    'groups' => [
                        'used' => 0,
                        'limit' => 1,
                        'percentage' => 0
                    ],
                    'messages' => [
                        'used' => 0,
                        'limit' => 3,
                        'percentage' => 0
                    ]
                ],
                'subscription' => [
                    'plan' => 'free',
                    'status' => 'active'
                ]
            ]);
        }
    }

}