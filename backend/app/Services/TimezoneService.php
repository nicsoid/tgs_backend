<?php
// app/Services/TimezoneService.php - Service for timezone handling

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class TimezoneService
{
    /**
     * Convert user time to UTC
     */
    public static function convertToUtc($userTime, $userTimezone = 'UTC')
    {
        try {
            $carbonTime = Carbon::parse($userTime, $userTimezone);
            return $carbonTime->utc()->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            Log::error("Error converting time to UTC", [
                'user_time' => $userTime,
                'user_timezone' => $userTimezone,
                'error' => $e->getMessage()
            ]);
            
            // Fallback: assume it's already UTC
            return Carbon::parse($userTime)->format('Y-m-d H:i:s');
        }
    }

    /**
     * Convert UTC time to user timezone
     */
    public static function convertFromUtc($utcTime, $userTimezone = 'UTC')
    {
        try {
            $carbonTime = Carbon::parse($utcTime, 'UTC');
            return $carbonTime->setTimezone($userTimezone);
        } catch (\Exception $e) {
            Log::error("Error converting time from UTC", [
                'utc_time' => $utcTime,
                'user_timezone' => $userTimezone,
                'error' => $e->getMessage()
            ]);
            
            // Fallback: return as is
            return Carbon::parse($utcTime);
        }
    }

    /**
     * Check if a time is in the future relative to user's timezone
     */
    public static function isFutureInUserTimezone($userTime, $userTimezone = 'UTC')
    {
        try {
            $carbonTime = Carbon::parse($userTime, $userTimezone);
            $nowInUserTimezone = Carbon::now($userTimezone);
            
            return $carbonTime->isAfter($nowInUserTimezone);
        } catch (\Exception $e) {
            Log::error("Error checking if time is future", [
                'user_time' => $userTime,
                'user_timezone' => $userTimezone,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }

    /**
     * Get current time in user's timezone
     */
    public static function nowInUserTimezone($userTimezone = 'UTC')
    {
        return Carbon::now($userTimezone);
    }

    /**
     * Format datetime for frontend (in user's timezone)
     */
    public static function formatForFrontend($utcTime, $userTimezone = 'UTC', $format = 'Y-m-d H:i:s')
    {
        try {
            $carbonTime = Carbon::parse($utcTime, 'UTC');
            return $carbonTime->setTimezone($userTimezone)->format($format);
        } catch (\Exception $e) {
            Log::error("Error formatting time for frontend", [
                'utc_time' => $utcTime,
                'user_timezone' => $userTimezone,
                'error' => $e->getMessage()
            ]);
            
            return $utcTime;
        }
    }
}