<?php

namespace App\Providers;

use App\Checklists\ChecklistSettings;
use App\Enums\Role;
use App\Observers\AuditObserver;
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
    public function register(): void
    {
        $this->app->scoped(ChecklistSettings::class);
    }

    public function boot(): void
    {
        if ($this->app->isProduction() && ! config('attendance.verify_tls')) {
            throw new RuntimeException('ATTENDANCE_API_VERIFY_TLS tidak boleh false di produksi.');
        }

        // Log aplikasi: setiap perubahan data utama dicatat otomatis (lihat AuditObserver).
        foreach (AuditObserver::MODELS as $model) {
            $model::observe(AuditObserver::class);
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
            && ($target->id !== $actor->employee_id || $actor->role->overridesReview())
            && $actor->canAccessDepartment($target->department_id));
        // Kendala kamar: semua pengguna berakses (termasuk Staff Front Office) boleh mencatat dan melihat yang menjadi bagiannya; hak per kendala ada di RoomIssuePolicy.
        Gate::define('room-issues', fn (User $user) => $user->canUseApp());
        Gate::policy(\App\Models\RoomIssue::class, \App\Policies\RoomIssuePolicy::class);
        // Notifikasi obrolan (Web Push): pengirim bisa diganti di tes.
        $this->app->bind(\App\Push\PushSender::class, \App\Push\WebPushSender::class);
        // Chronology Report (laporan kejadian tamu): semua pengguna berakses boleh membuat; hak per laporan ada di ChronologyReportPolicy.
        Gate::define('chronology-reports', fn (User $user) => $user->canUseApp());
        Gate::policy(\App\Models\ChronologyReport::class, \App\Policies\ChronologyReportPolicy::class);
        // Obrolan pekerjaan: hanya bila Administrator menyalakannya (Pengaturan); hak per percakapan ada di ChatThreadPolicy.
        Gate::define('chat', fn (User $user) => $user->canUseApp() && app(\App\Checklists\ChecklistSettings::class)->chatEnabled());
        Gate::policy(\App\Models\ChatThread::class, \App\Policies\ChatThreadPolicy::class);
        Gate::define('manage-tasks', fn (User $user) => $user->canUseApp() && in_array($user->role, [Role::Admin, Role::Hr], true));
        Gate::define('manage-access', fn (User $user) => $user->canUseApp() && $user->isAdmin());
    }
}
