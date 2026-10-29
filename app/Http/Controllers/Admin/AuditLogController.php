<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\DateRange;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/** Log aplikasi: semua aktivitas tercatat. Hanya Administrator (lewat Pengaturan). */
class AuditLogController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('manage-access');
        [$from, $to] = DateRange::fromRequest($request, 7, 92);
        $search = trim((string) $request->query('q'));
        $action = (string) $request->query('aksi', '');

        $logs = AuditLog::query()
            ->whereBetween('occurred_at', [$from->startOfDay(), $to->endOfDay()])
            ->when($action !== '', fn ($q) => $q->where('action', $action))
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $q->where(fn ($w) => $w->where('user_name', 'like', $like)->orWhere('subject_label', 'like', $like)->orWhere('action', 'like', $like)->orWhere('ip', 'like', $like));
            })
            ->orderByDesc('occurred_at')->orderByDesc('id')
            ->paginate(50)->withQueryString();

        return view('admin.logs.index', [
            'logs' => $logs,
            'from' => $from,
            'to' => $to,
            'today' => \Carbon\CarbonImmutable::today(),
            'search' => $search,
            'action' => $action,
            'actions' => AuditLog::query()->distinct()->orderBy('action')->pluck('action'),
        ]);
    }
}
