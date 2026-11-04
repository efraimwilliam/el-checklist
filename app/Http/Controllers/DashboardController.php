<?php

namespace App\Http\Controllers;

use App\Chronology\ChronologyInsight;
use App\Checklists\DashboardService;
use App\Support\DateRange;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Throwable;

/** Halaman utama HOD / GM / HR / Administrator: progres checklist per departemen untuk rentang tanggal yang dipilih. */
class DashboardController extends Controller
{
    /** Rentang bawaan: hari ini saja (lihat DateRange). */
    private const DEFAULT_DAYS = 1;
    private const MAX_DAYS = 92;

    public function __invoke(Request $request, DashboardService $dashboard, ChronologyInsight $chronology): View
    {
        Gate::authorize('view-checklists');
        [$from, $to] = DateRange::fromRequest($request, self::DEFAULT_DAYS, self::MAX_DAYS);

        return view('dashboard', $dashboard->build($request->user(), $from, $to) + [
            'today' => CarbonImmutable::today(),
            'chronology' => $chronology->build($request->user(), $from, $to),
        ]);
    }
}
