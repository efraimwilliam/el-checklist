<?php

namespace App\Providers;

use App\Enums\Role;
use App\Models\Employee;
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

        // Mengisi checklist (GM hanya melihat dan mereview) dan melihat daftar / riwayat checklist.
        Gate::define('fill-checklists', fn (User $user) => $user->canUseApp() && $user->role !== Role::Gm);
        Gate::define('view-checklists', fn (User $user) => $user->canUseApp() && $user->role !== Role::Staff);
        // Memilih tugas per pegawai Absensi: Administrator & HR (semua departemen) dan HOD (departemen yang ditugaskan). Tidak untuk diri sendiri, kecuali Administrator.
        Gate::define('manage-team-tasks', fn (User $user) => $user->canUseApp() && $user->role->managesOthers());
        Gate::define('assign-tasks', fn (User $actor, Employee $target) => Gate::forUser($actor)->allows('manage-team-tasks')
            && $target->is_active
            && $target->department_id !== null
            && ($target->id !== $actor->employee_id || $actor->isAdmin())
            && $actor->canAccessDepartment($target->department_id));
        Gate::define('manage-tasks', fn (User $user) => $user->canUseApp() && in_array($user->role, [Role::Admin, Role::Hr], true));
        Gate::define('manage-access', fn (User $user) => $user->canUseApp() && $user->isAdmin());
    }
}
