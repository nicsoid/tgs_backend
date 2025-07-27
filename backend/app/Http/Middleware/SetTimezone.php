<?php
// app/Http/Middleware/SetTimezone.php - Middleware to ensure consistent timezone handling

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Carbon\Carbon;

class SetTimezone
{
    public function handle(Request $request, Closure $next)
    {
        // Ensure Carbon always uses UTC as default
        Carbon::setDefaultTimezone('UTC');
        
        // Set PHP timezone to UTC
        date_default_timezone_set('UTC');
        
        return $next($request);
    }
}