<?php

namespace App\Reports;

use App\Models\Room;
use App\Models\RoomIssue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Peta kamar: kendala per kamar dalam satu rentang tanggal (tanggal dicatatnya). Dipakai halaman Laporan > Peta kamar.
 * Hanya kendala yang boleh dilihat pengguna (RoomIssue::visibleTo), jadi HOD / staf hanya melihat bagian departemennya.
 */
class RoomReport
{
    /** Kamar dengan kendala sebanyak ini atau lebih dalam rentang dianggap langganan masalah. */
    public const REPEAT_THRESHOLD = 3;

    /**
     * @return array{floors: list<array{floor: string, rooms: list<array<string, mixed>>}>, repeat: list<array<string, mixed>>, categories: list<array<string, mixed>>, departments: list<array<string, mixed>>, total: int, open: int, avgHours: ?float, max: int}
     */
    public function build(\App\Models\User $user, array $range, ?int $departmentId = null, ?int $categoryId = null): array
    {
        $issues = RoomIssue::visibleTo($user)
            ->whereBetween(DB::raw('date(room_issues.created_at)'), $range)
            ->when($departmentId, fn ($q, $id) => $q->where('assigned_department_id', $id))
            ->when($categoryId, fn ($q, $id) => $q->where('category_id', $id))
            ->with(['category', 'department', 'room'])->get();

        $byRoom = $issues->groupBy('room_id');
        $rooms = Room::where('is_active', true)->orWhereIn('id', $byRoom->keys())->orderBy('floor')->orderBy('sort')->orderBy('number')->get();

        $cells = $rooms->map(function (Room $room) use ($byRoom) {
            $mine = $byRoom->get($room->id, collect());

            return [
                'id' => $room->id,
                'number' => $room->number,
                'building' => $room->building,
                'floor' => $room->floor ?? '—',
                'type' => $room->type,
                'count' => $mine->count(),
                'open' => $mine->where('status', '!=', RoomIssue::STATUS_DONE)->count(),
                'urgent' => $mine->where('priority', 'mendesak')->count(),
                'recurrent' => $mine->where('recurrence', '>', 1)->count(),
                'top' => $mine->groupBy(fn ($i) => $i->category?->name ?? __('Tanpa kategori'))->map->count()->sortDesc()->keys()->first(),
            ];
        });

        $done = $issues->filter(fn ($i) => $i->completed_at !== null);
        $avg = $done->isEmpty() ? null : round($done->avg(fn ($i) => $i->created_at->diffInMinutes($i->completed_at)) / 60, 1);

        return [
            'floors' => $cells->groupBy('floor')->map(fn (Collection $rows, string $floor) => ['floor' => $floor, 'rooms' => $rows->values()->all()])
                ->sortBy(fn (array $f) => is_numeric($f['floor']) ? (int) $f['floor'] : 1000)->values()->all(),
            'repeat' => $cells->where('count', '>=', self::REPEAT_THRESHOLD)->sortByDesc('count')->values()->all(),
            'categories' => $issues->groupBy(fn ($i) => $i->category?->name ?? __('Tanpa kategori'))
                ->map(fn (Collection $rows, string $name) => ['name' => $name, 'count' => $rows->count(), 'open' => $rows->where('status', '!=', RoomIssue::STATUS_DONE)->count()])
                ->sortByDesc('count')->values()->all(),
            'departments' => $issues->groupBy(fn ($i) => $i->department?->name ?? __('Belum ditugaskan'))
                ->map(fn (Collection $rows, string $name) => ['name' => $name, 'count' => $rows->count(), 'open' => $rows->where('status', '!=', RoomIssue::STATUS_DONE)->count()])
                ->sortByDesc('count')->values()->all(),
            'total' => $issues->count(),
            'recurrences' => $issues->where('recurrence', '>', 1)->count(),
            'open' => $issues->where('status', '!=', RoomIssue::STATUS_DONE)->count(),
            'avgHours' => $avg,
            'rooms' => $cells->keyBy('id')->all(),
            // Per gedung / area (kolom Gedung pada kamar), dipakai kotak gedung di peta kawasan.
            'buildings' => $cells->filter(fn (array $c) => filled($c['building']))->groupBy(fn (array $c) => mb_strtolower($c['building']))
                ->map(fn (Collection $rows, string $key) => [
                    'name' => $rows->first()['building'],
                    'rooms' => $rows->count(),
                    'count' => $rows->sum('count'),
                    'open' => $rows->sum('open'),
                    'top' => $issues->filter(fn ($i) => filled($i->room?->building) && mb_strtolower($i->room->building) === $key)
                        ->groupBy(fn ($i) => $i->category?->name ?? __('Tanpa kategori'))->map->count()->sortDesc()->keys()->first(),
                ])->all(),
            'buildingMax' => (int) $cells->filter(fn (array $c) => filled($c['building']))->groupBy(fn (array $c) => mb_strtolower($c['building']))->map(fn (Collection $rows) => $rows->sum('count'))->max(),
            'max' => (int) $cells->max('count'),
        ];
    }

    /**
     * Kendala semua kamar di satu gedung / area (kolom Gedung pada kamar) dalam rentang, terbaru dulu.
     *
     * @return Collection<int, RoomIssue>
     */
    public function buildingHistory(\App\Models\User $user, string $building, array $range)
    {
        return RoomIssue::visibleTo($user)->whereHas('room', fn ($q) => $q->where('building', $building))
            ->whereBetween(DB::raw('date(room_issues.created_at)'), $range)
            ->with(['room', 'category', 'department'])->orderByDesc('created_at')->get();
    }

    /**
     * Riwayat satu kamar dalam rentang (terbaru dulu).
     *
     * @return Collection<int, RoomIssue>
     */
    public function history(\App\Models\User $user, Room $room, array $range)
    {
        return RoomIssue::visibleTo($user)->where('room_id', $room->id)
            ->whereBetween(DB::raw('date(room_issues.created_at)'), $range)
            ->with(['category', 'department'])->orderByDesc('created_at')->get();
    }
}
