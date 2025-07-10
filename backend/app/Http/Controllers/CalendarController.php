<?php
// app/Http/Controllers/CalendarController.php

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
            'group_id' => 'nullable|exists:groups,_id'
        ]);
        
        $user = $request->user();
        $timezone = $user->getTimezone();
        
        $startUtc = Carbon::parse($request->start_date, $timezone)->startOfDay()->utc();
        $endUtc = Carbon::parse($request->end_date, $timezone)->endOfDay()->utc();
        
        $query = ScheduledPost::where('user_id', $user->id)
            ->whereIn('status', ['pending', 'partially_sent']);
        
        if ($request->group_id) {
            $query->where('group_id', $request->group_id);
        }
        
        $posts = $query->get();
        
        $events = [];
        
        foreach ($posts as $post) {
            foreach ($post->schedule_times_utc as $index => $timeUtc) {
                $timeCarbon = Carbon::parse($timeUtc);
                
                if ($timeCarbon->between($startUtc, $endUtc)) {
                    $localTime = $timeCarbon->timezone($timezone);
                    
                    $events[] = [
                        'id' => $post->id . '_' . $index,
                        'title' => $post->group->title . ' - ' . $post->advertiser['telegram_username'],
                        'start' => $localTime->toIso8601String(),
                        'end' => $localTime->addMinutes(30)->toIso8601String(),
                        'backgroundColor' => $this->getEventColor($post->status),
                        'extendedProps' => [
                            'post_id' => $post->id,
                            'group' => $post->group->title,
                            'advertiser' => $post->advertiser['telegram_username'],
                            'amount' => $post->advertiser['amount_paid'],
                            'currency' => $post->advertiser['currency'],
                            'status' => $post->status,
                            'content_preview' => substr($post->content['text'], 0, 100)
                        ]
                    ];
                }
            }
        }
        
        // Add available slots
        $availableSlots = $this->findAvailableSlots($startUtc, $endUtc, $events, $timezone);
        
        return response()->json([
            'events' => $events,
            'available_slots' => $availableSlots
        ]);
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
                $localStart = $current->copy()->timezone($timezone);
                
                // Only add slots during reasonable hours (8 AM to 10 PM)
                if ($localStart->hour >= 8 && $localStart->hour < 22) {
                    $slots[] = [
                        'start' => $localStart->toIso8601String(),
                        'end' => $localStart->addMinutes($slotDuration)->toIso8601String()
                    ];
                }
            }
            
            $current->addMinutes($slotDuration);
        }
        
        return $slots;
    }
}