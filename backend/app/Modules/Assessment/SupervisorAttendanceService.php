<?php

namespace App\Modules\Assessment;

use App\Models\Attendance;
use App\Models\AuditEvent;
use App\Models\Employee;
use App\Models\EmployeeKpi;
use App\Models\KpiPeriod;
use App\Models\User;
use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class SupervisorAttendanceService
{
    public function roster(User $supervisor, string $date): array
    {
        $period = $this->periodForDate($date);
        $this->assertSupervisor($supervisor);
        $employees = $this->employeesFor($supervisor, $period);
        $attendances = Attendance::query()
            ->whereIn('employee_id', $employees->pluck('id'))
            ->whereDate('attendance_date', $date)
            ->get()
            ->keyBy('employee_id');

        return [
            'date' => $date,
            'period' => $period,
            'rows' => $employees->map(function (Employee $employee) use ($attendances): array {
                $attendance = $attendances->get($employee->id);

                return [
                    'employee_id' => (string) $employee->id,
                    'employee_number' => $employee->employee_number,
                    'name' => $employee->name,
                    'position' => $employee->position?->name,
                    'status' => $attendance?->status,
                    'status_label' => $attendance ? Attendance::statusLabel($attendance->status) : 'Belum dicatat',
                    'note' => $attendance?->note,
                    'attendance_id' => $attendance?->id,
                ];
            })->values(),
        ];
    }

    public function record(
        User $supervisor,
        string $date,
        array $statuses,
        array $notes = []
    ): array {
        $context = $this->roster($supervisor, $date);
        $employees = $this->employeesFor($supervisor, $context['period'])->keyBy(fn (Employee $employee): string => (string) $employee->id);

        DB::transaction(function () use ($supervisor, $date, $statuses, $notes, $employees): void {
            foreach ($statuses as $employeeId => $status) {
                $employee = $employees->get((string) $employeeId);
                if (! $employee) {
                    throw new AuthorizationException('Karyawan bukan anggota tim Supervisor pada snapshot periode ini.');
                }

                $status = $status === '' ? null : $status;
                if ($status !== null && ! in_array($status, Attendance::STATUSES, true)) {
                    throw new Exception('Status absensi tidak valid.');
                }

                $note = trim((string) ($notes[$employeeId] ?? '')) ?: null;
                if ($status !== null
                    && in_array($status, [...Attendance::EXCUSED_STATUSES, Attendance::STATUS_ABSENT], true)
                    && $note === null) {
                    throw new Exception("Catatan wajib diisi untuk {$employee->name}.");
                }

                $attendance = Attendance::query()
                    ->where('employee_id', $employee->id)
                    ->whereDate('attendance_date', $date)
                    ->first();
                $before = $attendance?->getAttributes();

                if ($status === null) {
                    if (! $attendance) {
                        continue;
                    }

                    $attendance->delete();
                    AuditEvent::log(
                        action: 'supervisor_attendance_cleared',
                        subjectType: 'Attendance',
                        subjectId: (string) $attendance->getKey(),
                        before: $before,
                        after: null,
                        reason: 'Status absensi dikembalikan menjadi belum dicatat.',
                        actorId: $supervisor->getKey(),
                    );

                    continue;
                }

                $attendance ??= new Attendance([
                    'employee_id' => $employee->id,
                    'attendance_date' => $date,
                ]);
                $attendance->branch_id = $employee->branch_id;
                $attendance->status = $status;
                $attendance->note = $note;
                $attendance->recorded_by = $supervisor->getKey();
                if (in_array($status, Attendance::EXCUSED_STATUSES, true) || $status === Attendance::STATUS_ABSENT) {
                    $attendance->check_in_time = null;
                    $attendance->check_out_time = null;
                }
                $attendance->save();

                AuditEvent::log(
                    action: 'supervisor_attendance_recorded',
                    subjectType: 'Attendance',
                    subjectId: (string) $attendance->getKey(),
                    before: $before,
                    after: $attendance->getAttributes(),
                    reason: $note,
                    actorId: $supervisor->getKey(),
                );
            }
        });

        return $this->roster($supervisor, $date);
    }

    private function employeesFor(User $supervisor, KpiPeriod $period): Collection
    {
        return EmployeeKpi::query()
            ->with(['employee.position'])
            ->where('period_id', $period->id)
            ->where('supervisor_id_snapshot', $supervisor->employee?->id)
            ->whereHas('employee', fn ($query) => $query->where('status', 'active'))
            ->get()
            ->map(fn (EmployeeKpi $kpi): ?Employee => $kpi->employee)
            ->filter()
            ->unique('id')
            ->sortBy('name')
            ->values();
    }

    private function periodForDate(string $date): KpiPeriod
    {
        try {
            $parsed = Carbon::createFromFormat('!Y-m-d', $date);
        } catch (\Throwable) {
            throw new Exception('Tanggal absensi harus menggunakan format YYYY-MM-DD.');
        }
        if (! $parsed || $parsed->format('Y-m-d') !== $date || $parsed->isFuture()) {
            throw new Exception('Tanggal absensi tidak valid atau belum terjadi.');
        }

        $period = KpiPeriod::query()
            ->where('status', 'OPEN')
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->orderByDesc('id')
            ->first();
        if (! $period) {
            throw new Exception('Tidak ada periode KPI terbuka untuk tanggal tersebut.');
        }

        return $period;
    }

    private function assertSupervisor(User $user): void
    {
        if ($user->hasRole('super_admin') || ! $user->hasRole('supervisor') || ! $user->employee?->id) {
            throw new AuthorizationException('Hanya Supervisor aktif yang dapat mencatat absensi tim.');
        }
    }
}
