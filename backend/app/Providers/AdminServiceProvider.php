<?php
// app/Providers/AdminServiceProvider.php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;
use App\Models\User;

class AdminServiceProvider extends ServiceProvider
{
    public function register()
    {
        //
    }

    public function boot()
    {
        // Define admin gates
        Gate::define('admin-access', function (User $user) {
            return $this->isAdmin($user);
        });

        Gate::define('manage-users', function (User $user) {
            return $this->isAdmin($user);
        });

        Gate::define('manage-posts', function (User $user) {
            return $this->isAdmin($user);
        });

        Gate::define('manage-groups', function (User $user) {
            return $this->isAdmin($user);
        });

        Gate::define('view-system-stats', function (User $user) {
            return $this->isAdmin($user);
        });
    }

    private function isAdmin(User $user): bool
    {
        // Define admin users by telegram_id or username
        $adminIds = explode(',', config('app.admin_telegram_ids', ''));
        $adminUsernames = explode(',', config('app.admin_usernames', ''));
        
        return in_array($user->telegram_id, $adminIds) || 
               in_array($user->username, $adminUsernames) ||
               ($user->settings['is_admin'] ?? false);
    }
}