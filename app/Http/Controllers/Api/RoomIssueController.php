<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\IssueCategory;
use App\Models\Room;
use App\Models\RoomIssue;
use App\Models\RoomIssueUpdate;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Kendala untuk aplikasi Android: hanya baca (daftar, isi form, detail). Mencatat, mengambil, melepas, dan status pekerjaan
 * memakai `App\Http\Controllers\RoomIssueController` yang sama dengan web (JSON bila dipanggil lewat /api).
 */
class RoomIssueController extends Controller
{
    // shortcut: 60 terbaru tanpa halaman berikutnya; tambahkan paginasi bila daftar Selesai perlu ditelusuri lebih jauh dari HP.
    private const LIMIT = 60;

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('room-issues');
        $user = $request->user();
        $done = $request->query('status') === 'selesai';

        $issues = RoomIssue::visibleTo($user)->with(['room', 'category', 'department', 'assignedPosition', 'assignedEmployee', 'claimer.employee'])
            ->where('status', $done ? '=' : '!=', RoomIssue::STATUS_DONE)
            ->when(! $done, fn ($q) => $q->orderByRaw("case when priority = 'mendesak' then 0 else 1 end"))
            ->orderByDesc($done ? 'completed_at' : 'created_at')
            ->limit(self::LIMIT)->get();

        return response()->json([
            'issues' => $issues->map(fn (RoomIssue $issue) => $this->summary($issue, $user))->values(),
            'new_for_me' => $user->newRoomIssueCount(),
        ]);
    }

    /** Pilihan untuk form Catat kendala. */
    public function form(): JsonResponse
    {
        Gate::authorize('room-issues');
        $used = RoomIssue::whereNotNull('location')->distinct()->pluck('location')->all();

        return response()->json([
            'rooms' => Room::where('is_active', true)->orderBy('floor')->orderBy('sort')->orderBy('number')->get(['id', 'number', 'floor']),
            'categories' => IssueCategory::where('is_active', true)->orderBy('sort')->orderBy('name')->get(['id', 'name', 'department_id']),
            'departments' => Department::where('is_active', true)->orderBy('sort')->orderBy('name')->get(['id', 'name']),
            'locations' => collect([...RoomIssue::SUGGESTED_LOCATIONS, ...$used])->unique(fn ($name) => mb_strtolower($name))->sort(SORT_NATURAL | SORT_FLAG_CASE)->values(),
            'sources' => collect(RoomIssue::sourceLabels())->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
        ]);
    }

    public function show(Request $request, RoomIssue $issue): JsonResponse
    {
        Gate::authorize('view', $issue);
        $user = $request->user();
        $issue->load(['room', 'category', 'department', 'assignedPosition', 'assignedEmployee', 'claimer.employee', 'reporter.employee', 'completer.employee', 'updates.user.employee', 'updates.department', 'updates.photos']);

        return response()->json(['issue' => $this->summary($issue, $user) + [
            'description' => $issue->description,
            'source' => RoomIssue::sourceLabels()[$issue->source] ?? $issue->source,
            'reporter' => $issue->reporter?->name(),
            'claimed_at' => $issue->claimed_at?->toIso8601String(),
            'completed_at' => $issue->completed_at?->toIso8601String(),
            'completer' => $issue->completer?->name(),
            'can' => collect(['claim', 'work', 'release'])->mapWithKeys(fn ($ability) => [$ability => $user->can($ability, $issue)]),
            'updates' => $issue->updates->sortBy('created_at')->values()->map(fn (RoomIssueUpdate $update) => [
                'id' => $update->id,
                'kind' => $update->kind,
                'body' => $update->body,
                'user' => $update->user?->name(),
                'department' => $update->department?->name,
                'at' => $update->created_at?->toIso8601String(),
                'photos' => $update->photos->map(fn ($photo) => ['id' => $photo->id])->values(),
            ]),
        ]]);
    }

    /** @return array<string, mixed> */
    private function summary(RoomIssue $issue, User $user): array
    {
        return [
            'id' => $issue->id,
            'place' => $issue->placeTitle(),
            'category' => $issue->category?->name,
            'status' => $issue->status,
            'status_label' => RoomIssue::statusLabels()[$issue->status] ?? $issue->status,
            'priority' => $issue->priority,
            'recurrence' => (int) $issue->recurrence,
            'excerpt' => Str::limit($issue->description, 140),
            'target' => $issue->targetLabel(),
            'claimer' => $issue->claimer?->name(),
            'for_me' => $issue->isForUser($user),
            'created_at' => $issue->created_at?->toIso8601String(),
        ];
    }
}
