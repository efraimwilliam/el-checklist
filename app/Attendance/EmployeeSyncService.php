<?php

namespace App\Attendance;

use App\Models\Department;
use App\Models\Employee;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Sinkron departemen & pegawai dari Sistem Absensi, dicocokkan lewat id absensi (external_id).
 * Pegawai tidak pernah dihapus: yang keluar / hilang dari absensi hanya dinonaktifkan supaya riwayat checklist tetap ada.
 */
class EmployeeSyncService
{
    public const LAST_SYNC_CACHE = 'attendance_last_sync';

    public function __construct(private AttendanceApiService $api) {}

    /**
     * @return array{departments: int, employees: int, deactivated: int}
     *
     * @throws AttendanceUnavailable
     */
    public function sync(): array
    {
        $departments = $this->api->departments();
        $employees = $this->api->employees();

        $result = DB::transaction(function () use ($departments, $employees) {
            $departmentIds = $this->syncDepartments($departments);
            $now = now();

            foreach ($employees as $row) {
                $employee = Employee::firstOrNew(['external_id' => $row['id']]);
                $employee->fill([
                    'username' => $row['username'],
                    'name' => $row['name'],
                    'position_title' => $row['position'],
                    // Departemen yang tidak dikenal: tetap departemen lama supaya akses HOD tidak berubah diam-diam.
                    'department_id' => $departmentIds[$row['department_id']] ?? $employee->department_id,
                    'is_active' => $row['is_active'],
                    'synced_at' => $now,
                ])->save();
            }

            $deactivated = Employee::where('is_active', true)->whereNotIn('external_id', array_column($employees, 'id'))->update(['is_active' => false]);

            return ['departments' => count($departmentIds), 'employees' => count($employees), 'deactivated' => $deactivated];
        });

        Cache::forever(self::LAST_SYNC_CACHE, $result + ['at' => now()->toIso8601String()]);

        return $result;
    }

    /**
     * Cocokkan lewat id absensi, lalu kode, lalu nama (departemen dari impor workbook belum punya id absensi). Nama mengikuti absensi.
     *
     * @param  list<array{id: int, code: ?string, name: string}>  $rows
     * @return array<int, int> [id absensi => id lokal]
     */
    private function syncDepartments(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            $code = $row['code'] ? strtoupper(trim($row['code'])) : null;
            $department = Department::where('external_id', $row['id'])->first()
                ?? ($code ? Department::whereNull('external_id')->where('code', $code)->first() : null)
                ?? Department::whereNull('external_id')->whereRaw('LOWER(name) = ?', [mb_strtolower($row['name'])])->first()
                ?? new Department;

            $department->fill(['external_id' => $row['id'], 'name' => $row['name'], 'is_active' => true]);
            if ($code && ! Department::where('code', $code)->whereKeyNot($department->id)->exists()) {
                $department->code = $code;
            }
            $department->save();
            $map[$row['id']] = $department->id;
        }

        return $map;
    }
}
