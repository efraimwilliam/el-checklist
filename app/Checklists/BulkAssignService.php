<?php

namespace App\Checklists;

use App\Attendance\AttendanceApiService;
use App\Attendance\AttendanceUnavailable;
use App\Models\Employee;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Membuatkan checklist kosong sekaligus untuk semua staff yang masuk kerja pada satu tanggal, menurut jadwal di Absensi.
 * Dilewati: libur (OFF), izin / cuti / sakit / PH, jadwal yang belum diisi, dan staff yang jabatan checklistnya belum diatur.
 * Aman dijalankan berulang: yang sudah ada tidak dibuat ganda. Checklist yang dibuatkan ditandai (assigned_at) dan tidak dibersihkan walau kosong.
 */
class BulkAssignService
{
    public function __construct(private AttendanceApiService $api, private ChecklistService $checklists) {}

    /**
     * @param  list<int>  $departmentIds
     * @return array{date: string, created: int, existing: int, off: int, leave: array<string, int>, unscheduled: int, no_position: list<array{id: int, name: string}>}
     *
     * @throws AttendanceUnavailable
     */
    public function run(array $departmentIds, CarbonImmutable $date, ?User $by): array
    {
        $schedules = $this->api->schedules($date);
        $summary = ['date' => $date->toDateString(), 'created' => 0, 'existing' => 0, 'off' => 0, 'leave' => [], 'unscheduled' => 0, 'no_position' => []];

        $employees = Employee::with('checklistPosition')->whereIn('department_id', $departmentIds)->where('is_active', true)->orderBy('name')->get();
        foreach ($employees as $employee) {
            $schedule = $schedules[$employee->external_id] ?? ['status' => 'unscheduled', 'code' => null];

            if ($schedule['status'] === 'off') {
                $summary['off']++;
            } elseif ($schedule['status'] === 'leave') {
                $code = $schedule['code'] ?? '-';
                $summary['leave'][$code] = ($summary['leave'][$code] ?? 0) + 1;
            } elseif ($schedule['status'] === 'unscheduled') {
                $summary['unscheduled']++;
            } else {
                $position = $employee->checklistPosition;
                if (! $position || ! $position->is_active || $position->department_id !== $employee->department_id || $this->checklists->tasksFor($employee, $position)->isEmpty()) {
                    $summary['no_position'][] = ['id' => $employee->id, 'name' => $employee->name];
                } elseif ($this->checklists->find($employee, $position, $date)) {
                    $summary['existing']++;
                } else {
                    $this->checklists->start($employee, $position, $date, $by, null, assigned: true);
                    $summary['created']++;
                }
            }
        }
        ksort($summary['leave']);

        return $summary;
    }
}
