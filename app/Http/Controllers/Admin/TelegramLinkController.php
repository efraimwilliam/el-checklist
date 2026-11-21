<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

/** HR / Administrator: tautan penghubung Telegram per pegawai (sekali ketuk di HP pegawai, tanpa login aplikasi). */
class TelegramLinkController extends Controller
{
    public const DAYS = 3;

    public function index(): View
    {
        $this->authorizeBot();
        $bot = ltrim((string) config('services.telegram.username'), '@');
        $users = User::with('employee.department')->get()->filter->canUseApp()
            ->sortBy(fn (User $u) => [$u->telegram_id ? 1 : 0, $u->employee?->department?->name, $u->name()])->values();

        // Yang belum tertaut dibuatkan tautan bila belum ada atau sudah kedaluwarsa; yang masih berlaku dipakai ulang.
        $users->whereNull('telegram_id')->each(function (User $u) {
            if (! $u->telegram_link_code || $u->telegram_link_expires_at?->isPast()) {
                $this->issue($u);
            }
        });

        $links = $users->whereNull('telegram_id')->mapWithKeys(fn (User $u) => [$u->id => 'https://t.me/'.$bot.'?start='.$u->fresh()->telegram_link_code]);

        return view('admin.telegram-links', ['users' => $users, 'links' => $links, 'days' => self::DAYS, 'bot' => $bot]);
    }

    /** Ganti tautan (mis. sudah terlanjur dibagikan ke orang yang salah). */
    public function rotate(User $user): RedirectResponse
    {
        $this->authorizeBot();
        $user->telegram_id === null && $this->issue($user);

        return back()->with('status', __('Tautan baru dibuat untuk :name.', ['name' => $user->name()]));
    }

    public function unlink(User $user): RedirectResponse
    {
        $this->authorizeBot();
        User::whereKey($user->id)->update(['telegram_id' => null]);

        return back()->with('status', __('Telegram :name diputuskan.', ['name' => $user->name()]));
    }

    private function issue(User $user): void
    {
        User::whereKey($user->id)->update(['telegram_link_code' => Str::lower(Str::random(20)), 'telegram_link_expires_at' => now()->addDays(self::DAYS)]);
    }

    private function authorizeBot(): void
    {
        Gate::authorize('manage-tasks');
        abort_if(blank(config('services.telegram.token')), 404);
    }
}
