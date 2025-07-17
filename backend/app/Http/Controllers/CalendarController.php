<?php
// app/Http/Controllers/CalendarController.php - Improved Group Filtering

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
        $selectedGroupId = $request->group_id;
        
        // Convert request dates to UTC for database queries
        $startUtc = Carbon::parse($request->start_date, $timezone)->startOfDay()->utc();
        $endUtc = Carbon::parse($request->end_date, $timezone)->endOfDay()->utc();
        
        $query = ScheduledPost::where('user_id', $user->id)
            ->whereIn('status', ['pending', 'partially_sent', 'completed']);
        
        // Don't filter posts by group here - we need all posts to calculate conflicts properly
        $posts = $query->get();
        
        $events = [];
        $allEventsForConflicts = []; // Track all events for conflict detection
        
        foreach ($posts as $post) {
            $scheduleTimes = $post->schedule_times_utc ?? $post->schedule_times ?? [];
            
            foreach ($scheduleTimes as $index => $timeUtc) {
                try {
                    // Parse the time properly
                    if (isset($post->schedule_times_utc)) {
                        $timeCarbon = Carbon::parse($timeUtc, 'UTC');
                    } else {
                        $timeCarbon = Carbon::parse($timeUtc, $timezone)->utc();
                    }
                    
                    if ($timeCarbon->between($startUtc, $endUtc)) {
                        $localTime = $timeCarbon->setTimezone($timezone);
                        
                        // Get ALL groups for this post
                        $groups = $post->groups ?? collect();
                        if ($groups->isEmpty() && $post->group_ids) {
                            $groups = \App\Models\Group::whereIn('_id', $post->group_ids)->get();
                        }
                        
                        $groupTitles = $groups->pluck('title')->toArray();
                        $groupIds = $groups->pluck('_id')->toArray();
                        
                        // Check if this post involves the selected group
                        $involvesSelectedGroup = !$selectedGroupId || in_array($selectedGroupId, $groupIds);
                        
                        // Always add to conflict detection array
                        $eventData = [
                            'start' => $localTime->toIso8601String(),
                            'group_ids' => $groupIds,
                            'involves_selected_group' => $involvesSelectedGroup
                        ];
                        $allEventsForConflicts[] = $eventData;
                        
                        // Only add to display events if it matches the filter
                        if ($involvesSelectedGroup) {
                            $advertiserUsername = $post->advertiser['telegram_username'] ?? 'Unknown';
                            $shortTitle = '@' . $advertiserUsername;
                            if (count($groupTitles) > 1) {
                                $shortTitle .= ' (' . count($groupTitles) . ' groups)';
                            }
                            
                            $events[] = [
                                'id' => $post->id . '_' . $index,
                                'title' => $shortTitle,
                                'start' => $localTime->toIso8601String(),
                                'end' => $localTime->toIso8601String(),
                                'backgroundColor' => $this->getEventColor($post->status),
                                'extendedProps' => [
                                    'post_id' => $post->id,
                                    'groups' => $groupTitles,
                                    'groups_text' => $this->formatGroupsForDisplay($groupTitles),
                                    'groups_count' => count($groupTitles),
                                    'advertiser' => $advertiserUsername,
                                    'amount' => $post->advertiser['amount_paid'] ?? 0,
                                    'currency' => $post->advertiser['currency'] ?? 'USD',
                                    'status' => $post->status,
                                    'content_preview' => substr($post->content['text'] ?? '', 0, 150),
                                    'can_edit' => $post->status === 'pending',
                                    'scheduled_time' => $localTime->format('Y-m-d H:i:s T')
                                ]
                            ];
                        }
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
        
        // Generate available slots based on selected group
        $availableSlots = $this->findAvailableSlots($startUtc, $endUtc, $allEventsForConflicts, $timezone, $selectedGroupId);
        
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
    
    private function findAvailableSlots($start, $end, $allEvents, $timezone, $selectedGroupId = null)
    {
        $slots = [];
        $slotDuration = 30; // minutes
        
        $current = $start->copy();
        
        while ($current < $end) {
            $slotEnd = $current->copy()->addMinutes($slotDuration);
            
            // Check if this slot has conflicts
            $hasConflict = false;
            
            foreach ($allEvents as $event) {
                $eventStart = Carbon::parse($event['start']);
                
                // Check if the event time falls within this slot
                if ($current <= $eventStart && $slotEnd > $eventStart) {
                    if ($selectedGroupId) {
                        // If a specific group is selected, only consider it a conflict 
                        // if the event involves that group
                        if (in_array($selectedGroupId, $event['group_ids'])) {
                            $hasConflict = true;
                            break;
                        }
                    } else {
                        // If no group selected, any event is a conflict
                        $hasConflict = true;
                        break;
                    }
                }
            }
            
            if (!$hasConflict) {
                $localStart = $current->copy()->setTimezone($timezone);
                
                $slots[] = [
                    'start' => $localStart->toIso8601String(),
                    'end' => $localStart->copy()->addMinutes($slotDuration)->toIso8601String()
                ];
            }
            
            $current->addMinutes($slotDuration);
        }
        
        return $slots;
    }
}