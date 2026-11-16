<?php

namespace App\Checklists;

use App\Enums\ChecklistStatus;
use App\Models\Checklist;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Papan tim Isi checklist (HOD / HR / Administrator): setiap staff aktif satu departemen pada satu tanggal beserta checklist dan
 * keadaannya. Dipakai halaman web (`FillController`) dan aplikasi Android (`Api\BoardController`) supaya statusnya selalu sama.
 */
final class TeamBoard
{
    public function __construct(private ChecklistService $service) {}

    /**
     * @param  Collection<int, Employee>  $employees  staff aktif departemen itu
     * @param  array<string, array{status: string}>|null  $schedules  jadwal Absensi; null = tidak terbaca (tidak ada yang ditandai)
     * @return Collection<int, array{employee: Employee, checklists: Collection<int, Checklist>, state: string, removable_ids: list<int>, canStart: bool}>
     */
    public function rows(User $user, Collection $employees, Department $department, CarbonImmutable $date, ?array $schedules): Collection
    {
        $day = Checklist::with('items.parameters', 'items.photos')->where('department_id', $department->id)->whereDate('date', $date)->get()->groupBy('employee_id');

        return $employees->load('checklistPosition')
            // Dirinya sendiri disembunyikan, kecuali Admin / HR atau sudah punya checklist.
            ->reject(fn (Employee $e) => $e->id === $user->employee_id && ! $user->role->overridesReview() && ! $day->has($e->id))
            ->map(function (Employee $e) use ($day, $user, $schedules) {
                $checklists = $day->get($e->id, collect());
                // Staff tanpa jadwal (kosong / belum diisi) tidak bisa dibuatkan checklist, jadi ditandai.
                $noSchedule = $schedules !== null && ($schedules[$e->external_id]['status'] ?? 'unscheduled') === 'unscheduled';
                $state = match (true) {
                    $checklists->isEmpty() && $noSchedule => 'noschedule',
                    $checklists->isEmpty() => 'none',
                    $checklists->every(fn (Checklist $c) => $c->isReviewed()) => 'reviewed',
                    $checklists->every(fn (Checklist $c) => $c->status !== ChecklistStatus::Saved) => 'hod_reviewed',
                    $checklists->every(fn (Checklist $c) => $c->isComplete()) => 'complete',
                    $checklists->sum(fn (Checklist $c) => $c->answeredCount()) === 0 => 'empty',
                    default => 'partial',
                };

                return [
                    'employee' => $e,
                    'checklists' => $checklists,
                    'state' => $state,
                    // Rencana masa depan yang belum diisi boleh dihapus (per pegawai, atau sekaligus per departemen).
                    'removable_ids' => $checklists->filter(fn (Checklist $c) => $c->date->isFuture() && $user->can('plan', $c) && ! $this->service->hasAnswers($c))->pluck('id')->values()->all(),
                    'canStart' => $state !== 'noschedule' && $e->checklistPosition && $user->can('fill', [Checklist::class, $e, $e->checklistPosition]),
                ];
            })->values();
    }
}
