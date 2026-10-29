<?php

namespace App\Providers;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        if ($this->app->isProduction() && ! config('attendance.verify_tls')) {
            throw new RuntimeException('ATTENDANCE_API_VERIFY_TLS tidak boleh false di produksi.');
        }

        // Batas kasar per IP untuk form login; kunci per username + IP ada di AttendanceLoginController.
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));

        Gate::define('review-checklists', fn (User $user) => $user->canUseApp() && $user->role->reviews());
        Gate::define('manage-tasks', fn (User $user) => $user->canUseApp() && in_array($user->role, [Role::Admin, Role::Hr], true));
        Gate::define('manage-access', fn (User $user) => $user->canUseApp() && $user->isAdmin());
    }
}
