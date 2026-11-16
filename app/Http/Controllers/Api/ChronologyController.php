<?php

namespace App\Http\Controllers\Api;

use App\Chronology\ReportText;
use App\Http\Controllers\Controller;
use App\Models\ChronologyReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Chronology Report untuk aplikasi Android: daftar dan detail (hanya baca). Simpan draft / finalisasi memakai
 * `App\Http\Controllers\ChronologyReportController` yang sama (JSON bila lewat /api); izin di ChronologyReportPolicy.
 */
class ChronologyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('chronology-reports');
        // shortcut: 60 terbaru tanpa pencarian; cari laporan lama lewat web.
        $reports = ChronologyReport::visibleTo($request->user())->with(['guests', 'author.employee'])->withCount('entries')
            ->orderByDesc('created_at')->limit(60)->get();

        return response()->json(['reports' => $reports->map(fn (ChronologyReport $r) => [
            'id' => $r->id,
            'guest' => $r->guests->first()?->name,
            'room' => $r->guests->pluck('room_number')->filter()->unique()->implode(', '),
            'status' => $r->status,
            'author' => $r->author?->name(),
            'entries' => $r->entries_count,
            'created_at' => $r->created_at?->toIso8601String(),
        ])->values()]);
    }

    public function show(Request $request, ChronologyReport $report): JsonResponse
    {
        Gate::authorize('view', $report);
        $report->load(['guests', 'entries', 'author.employee']);
        $user = $request->user();

        return response()->json(['report' => [
            'id' => $report->id,
            'status' => $report->status,
            'nationality' => $report->nationality,
            'check_in' => $report->check_in?->toDateString(),
            'check_out' => $report->check_out?->toDateString(),
            'booking_source' => $report->booking_source,
            'guests' => $report->guests->map(fn ($g) => ['name' => $g->name, 'room_number' => $g->room_number])->values(),
            'entries' => $report->entries->map(fn ($e) => ['body' => $e->body])->values(),
            'text' => ReportText::plain($report),
            'can' => ['update' => $user->can('update', $report)],
        ]]);
    }
}
