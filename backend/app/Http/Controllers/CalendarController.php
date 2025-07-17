<?php
// app/Http/Controllers/CalendarController.php - Fixed 24h Version

namespace App\Http\Controllers;

use App\Models\ScheduledPost;
use Illuminate\Http\Request;
use Carbon\Carbon;

class CalendarController extends Controller
{
    public function getCalendarData(Request $request)
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'group_id' => 'nullable|string'
        ]);
        
        $user = $request->user();
        $timezone = $user->getTimezone();
        
        // Convert request dates to UTC for database queries
        $startUtc = Carbon::parse($request->start_date, $timezone)->startOfDay()->utc();
        $endUtc = Carbon::parse($request->end_date, $timezone)->endOfDay()->utc();
        
        $query = ScheduledPost::where('user_id', $user->id)
            ->whereIn('status', ['pending', 'partially_sent', 'completed']);
        
        if ($request->group_id) {
            $query->where(function($q) use ($request) {
                $q->whereJsonContains('group_ids', $request->group_id)
                  ->orWhere('group_id', $request->group_id);
            });
        }
        
        $posts = $query->get();
        
        $events = [];
        
        foreach ($posts as $post) {
            $scheduleTimes = $post->schedule_times_utc ?? $post->schedule_times ?? [];
            
            foreach ($scheduleTimes as $index => $timeUtc) {
                try {
                    // Parse the time properly
                    if (isset($post->schedule_times_utc)) {
                        // Time is already in UTC
                        $timeCarbon = Carbon::parse($timeUtc, 'UTC');
                    } else {
                        // Time is in user's timezone, convert to UTC
                        $timeCarbon = Carbon::parse($timeUtc, $timezone)->utc();
                    }
                    
                    if ($timeCarbon->between($startUtc, $endUtc)) {
                        // Convert to user's local timezone for display
                        $localTime = $timeCarbon->setTimezone($timezone);
                        
                        // Get ALL groups for this post
                        $groups = $post->groups ?? collect();
                        if ($groups->isEmpty() && $post->group_ids) {
                            $groups = \App\Models\Group::whereIn('_id', $post->group_ids)->get();
                        }
                        
                        // Create group information
                        $groupTitles = $groups->pluck('title')->toArray();
                        $groupsText = $this->formatGroupsForDisplay($groupTitles);
                        
                        $advertiserUsername = $post->advertiser['telegram_username'] ?? 'Unknown';
                        
                        // Create shortened title for calendar display
                        $shortTitle = '@' . $advertiserUsername;
                        if (count($groupTitles) > 1) {
                            $shortTitle .= ' (' . count($groupTitles) . ' groups)';
                        }
                        
                        $events[] = [
                            'id' => $post->id . '_' . $index,
                            'title' => $shortTitle,
                            'start' => $localTime->toIso8601String(), // This will be in user's timezone
                            'end' => $localTime->copy()->addMinutes(30)->toIso8601String(),
                            'backgroundColor' => $this->getEventColor($post->status),
                            'extendedProps' => [
                                'post_id' => $post->id,
                                'groups' => $groupTitles,
                                'groups_text' => $groupsText,
                                'groups_count' => count($groupTitles),
                                'advertiser' => $advertiserUsername,
                                'amount' => $post->advertiser['amount_paid'] ?? 0,
                                'currency' => $post->advertiser['currency'] ?? 'USD',
                                'status' => $post->status,
                                'content_preview' => substr($post->content['text'] ?? '', 0, 150),
                                'can_edit' => $post->status === 'pending',
                                'scheduled_time' => $localTime->format('Y-m-d H:i:s T') // Readable time with timezone
                            ]
                        ];
                    }
                } catch (\Exception $e) {
                    \Log::error('Error processing schedule time', [
                        'post_id' => $post->id,
                        'time' => $timeUtc,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }
        
        // Generate available slots for full 24 hours
        $availableSlots = $this->findAvailableSlots($startUtc, $endUtc, $events, $timezone);
        
        return response()->json([
            'events' => $events,
            'available_slots' => $availableSlots,
            'timezone' => $timezone
        ]);
    }
    
    /**
     * Format group names for display
     */
    private function formatGroupsForDisplay($groupTitles)
    {
        if (empty($groupTitles)) {
            return 'No groups';
        }
        
        if (count($groupTitles) === 1) {
            return $groupTitles[0];
        }
        
        if (count($groupTitles) === 2) {
            return implode(' and ', $groupTitles);
        }
        
        // For 3+ groups, show first two and count
        return $groupTitles[0] . ', ' . $groupTitles[1] . ' and ' . (count($groupTitles) - 2) . ' more';
    }
    
    private function getEventColor($status)
    {
        return [
            'pending' => '#FFA500',
            'partially_sent' => '#1E90FF',
            'completed' => '#32CD32',
            'failed' => '#DC143C'
        ][$status] ?? '#808080';
    }
    
    private function findAvailableSlots($start, $end, $events, $timezone)
    {
        $slots = [];
        $slotDuration = 30; // minutes
        
        $current = $start->copy();
        
        while ($current < $end) {
            $slotEnd = $current->copy()->addMinutes($slotDuration);
            
            // Check if this slot overlaps with any event
            $hasConflict = collect($events)->some(function($event) use ($current, $slotEnd) {
                $eventStart = Carbon::parse($event['start']);
                $eventEnd = Carbon::parse($event['end']);
                
                return $current < $eventEnd && $slotEnd > $eventStart;
            });
            
            if (!$hasConflict) {
                $localStart = $current->copy()->setTimezone($timezone);
                
                // Show available slots for ALL hours (24/7)
                // You can uncomment the line below if you want to limit to business hours
                // if ($localStart->hour >= 8 && $localStart->hour < 22) {
                    $slots[] = [
                        'start' => $localStart->toIso8601String(),
                        'end' => $localStart->copy()->addMinutes($slotDuration)->toIso8601String()
                    ];
                // }
            }
            
            $current->addMinutes($slotDuration);
        }
        
        return $slots;
    }
}