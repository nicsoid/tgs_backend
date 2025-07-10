<?php
// app/Http/Controllers/UserController.php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Currency;

class UserController extends Controller
{
    public function getSettings(Request $request)
    {
        return response()->json([
            'settings' => $request->user()->settings,
            'available_timezones' => timezone_identifiers_list(),
            'available_languages' => config('app.available_locales'),
            'available_currencies' => Currency::all()
        ]);
    }

    public function updateSettings(Request $request)
    {
        $request->validate([
            'timezone' => 'required|timezone',
            'language' => 'required|in:' . implode(',', config('app.available_locales')),
            'currency' => 'required|exists:currencies,code'
        ]);

        $user = $request->user();
        $user->settings = array_merge($user->settings, $request->only(['timezone', 'language', 'currency']));
        $user->save();

        return response()->json([
            'message' => 'Settings updated successfully',
            'settings' => $user->settings
        ]);
    }
}