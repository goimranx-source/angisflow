<?php

namespace App\Domain\HR;

use App\Models\Attendance;
use App\Models\Department;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\LeaveBalance;
use App\Models\Position;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * HR operations: hiring, org structure, attendance tracking.
 *
 * Services own the invariants. The rules that make HR data trustworthy belong
 * here, not scattered across controllers or quietly missing from direct model
 * writes.
 */
class HRService
{
    /**
     * Seed job levels with the standard ladder.
     *
     * Any business can rename or extend these, but they start with a working
     * structure rather than an empty list nobody fills.
     */
    public function seedJobLevels(int $businessId): void
    {
        $accountId = DB::table('businesses')->where('id', $businessId)->value('account_id');

        if (! $accountId) {
            throw new InvalidArgumentException("Business {$businessId} not found.");
        }

        $existing = JobLevel::where('business_id', $businessId)->count();

        if ($existing > 0) {
            throw new InvalidArgumentException("Job levels already exist for this business — refusing to overwrite.");
        }

        foreach (JobLevel::DEFAULTS as $level) {
            JobLevel::create([
                'account_id' => $accountId,
                'business_id' => $businessId,
                'name' => $level['name'],
                'band' => $level['band'],
                'rank' => $level['rank'],
                'description' => $level['description'],
                'is_active' => true,
            ]);
        }
    }

    /**
     * Create a department.
     *
     * @param array{name: string, code?: string|null, description?: string|null, is_active?: bool} $data
     */
    public function createDepartment(int $businessId, array $data): Department
    {
        $accountId = DB::table('businesses')->where('id', $businessId)->value('account_id');

        if (! $accountId) {
            throw new InvalidArgumentException("Business {$businessId} not found.");
        }

        if (empty($data['name'])) {
            throw new InvalidArgumentException('Department name is required.');
        }

        if (! empty($data['code'])) {
            $exists = Department::where('business_id', $businessId)
                ->where('code', $data['code'])
                ->exists();

            if ($exists) {
                throw new InvalidArgumentException("The department code {$data['code']} is already in use.");
            }
        }

        return Department::create(array_merge([
            'account_id' => $accountId,
            'business_id' => $businessId,
            'is_active' => true,
        ], $data));
    }

    /**
     * Create a position (job title, carrying a pay band).
     *
     * @param array{
     *   title: string,
     *   department_id?: int|null,
     *   job_level_id?: int|null,
     *   role_id?: int|null,
     *   salary_min?: float|null,
     *   salary_max?: float|null,
     *   description?: string|null,
     *   is_active?: bool
     * } $data
     */
    public function createPosition(int $businessId, array $data): Position
    {
        $accountId = DB::table('businesses')->where('id', $businessId)->value('account_id');

        if (! $accountId) {
            throw new InvalidArgumentException("Business {$businessId} not found.");
        }

        if (empty($data['title'])) {
            throw new InvalidArgumentException('Position title is required.');
        }

        if (! empty($data['department_id'])) {
            $departmentExists = Department::where('business_id', $businessId)
                ->where('id', $data['department_id'])
                ->exists();

            if (! $departmentExists) {
                throw new InvalidArgumentException('That department does not belong to this business.');
            }
        }

        if (isset($data['salary_min'], $data['salary_max'])
            && $data['salary_min'] !== null
            && $data['salary_max'] !== null
            && $data['salary_min'] > $data['salary_max']
        ) {
            throw new InvalidArgumentException('The minimum of the pay band cannot be above its maximum.');
        }

        return Position::create(array_merge([
            'account_id' => $accountId,
            'business_id' => $businessId,
            'is_active' => true,
        ], $data));
    }

    /**
     * Hire someone.
     *
     * @param array{
     *   name: string,
     *   code?: string|null,
     *   phone?: string|null,
     *   email?: string|null,
     *   linked_user_id?: int|null,
     *   location_id?: int|null,
     *   department_id?: int|null,
     *   position_id?: int|null,
     *   manager_id?: int|null,
     *   date_of_birth?: string|null,
     *   joined_on?: string|null,
     *   base_salary?: float,
     *   pay_type?: string,
     *   payment_method?: string,
     *   status?: string,
     *   commission_type?: string,
     *   commission_rate?: float|null
     * } $data
     */
    public function hire(int $businessId, array $data): Employee
    {
        $accountId = DB::table('businesses')->where('id', $businessId)->value('account_id');

        if (! $accountId) {
            throw new InvalidArgumentException("Business {$businessId} not found.");
        }

        if (empty($data['name'])) {
            throw new InvalidArgumentException('Employee name is required.');
        }

        // If a code is given, it must be unique within the business
        if (! empty($data['code'])) {
            $exists = Employee::where('business_id', $businessId)
                ->where('code', $data['code'])
                ->exists();

            if ($exists) {
                throw new InvalidArgumentException("The employee code {$data['code']} is already in use.");
            }
        }

        // If linking to a user, that user must not already be linked elsewhere
        if (! empty($data['linked_user_id'])) {
            $alreadyLinked = Employee::where('business_id', $businessId)
                ->where('linked_user_id', $data['linked_user_id'])
                ->exists();

            if ($alreadyLinked) {
                throw new InvalidArgumentException('This user is already linked to another employee in this business.');
            }
        }

        return Employee::create(array_merge([
            'account_id' => $accountId,
            'business_id' => $businessId,
            'base_salary' => 0,
            'pay_type' => 'monthly',
            'payment_method' => 'bank',
            'status' => 'active',
            'commission_type' => 'none',
        ], $data));
    }

    /**
     * Record attendance for one day.
     *
     * @param array{
     *   date: string,
     *   clock_in?: string|null,
     *   clock_out?: string|null,
     *   status?: string,
     *   leave_type?: string|null,
     *   hours_worked?: float|null,
     *   hours_overtime?: float|null,
     *   location_id?: int|null,
     *   notes?: string|null
     * } $data
     */
    public function recordAttendance(int $employeeId, array $data): Attendance
    {
        $employee = Employee::findOrFail($employeeId);

        if (empty($data['date'])) {
            throw new InvalidArgumentException('Date is required for attendance.');
        }

        // One record per employee per day
        $existing = Attendance::where('employee_id', $employeeId)
            ->where('date', $data['date'])
            ->first();

        if ($existing) {
            throw new InvalidArgumentException("Attendance for {$data['date']} already recorded — update it instead.");
        }

        $attendance = Attendance::create(array_merge([
            'account_id' => $employee->account_id,
            'business_id' => $employee->business_id,
            'employee_id' => $employeeId,
            'status' => 'present',
        ], $data));

        // If clock times given but hours not, calculate them
        if ($attendance->clock_in && $attendance->clock_out && ! $attendance->hours_worked) {
            $attendance->hours_worked = $attendance->calculateHours();
            $attendance->save();
        }

        // If this is a leave day, deduct from balance
        if ($attendance->status === 'on_leave' && $attendance->leave_type === 'annual') {
            $this->deductLeave($employeeId, $data['date'], 1.0);
        }

        return $attendance;
    }

    /**
     * Clock in.
     */
    public function clockIn(
        int $employeeId,
        ?string $date = null,
        ?int $locationId = null,
        ?string $lat = null,
        ?string $lng = null
    ): Attendance {
        $employee = Employee::findOrFail($employeeId);
        $date = $date ?: now()->toDateString();

        $existing = Attendance::where('employee_id', $employeeId)
            ->where('date', $date)
            ->first();

        if ($existing) {
            if ($existing->clock_in) {
                throw new InvalidArgumentException('Already clocked in for today.');
            }

            $existing->update([
                'clock_in' => now()->format('H:i:s'),
                'clock_in_lat' => $lat,
                'clock_in_lng' => $lng,
                'location_id' => $locationId,
            ]);

            return $existing;
        }

        return Attendance::create([
            'account_id' => $employee->account_id,
            'business_id' => $employee->business_id,
            'employee_id' => $employeeId,
            'date' => $date,
            'clock_in' => now()->format('H:i:s'),
            'clock_in_lat' => $lat,
            'clock_in_lng' => $lng,
            'location_id' => $locationId,
            'status' => 'present',
        ]);
    }

    /**
     * Clock out.
     */
    public function clockOut(
        int $employeeId,
        ?string $date = null,
        ?string $lat = null,
        ?string $lng = null
    ): Attendance {
        $date = $date ?: now()->toDateString();

        $attendance = Attendance::where('employee_id', $employeeId)
            ->whereDate('date', $date)
            ->firstOrFail();

        if (! $attendance->clock_in) {
            throw new InvalidArgumentException('Cannot clock out without clocking in first.');
        }

        if ($attendance->clock_out) {
            throw new InvalidArgumentException('Already clocked out for today.');
        }

        $attendance->update([
            'clock_out' => now()->format('H:i:s'),
            'clock_out_lat' => $lat,
            'clock_out_lng' => $lng,
        ]);

        // Recalculate hours
        $attendance->hours_worked = $attendance->calculateHours();
        $attendance->save();

        return $attendance;
    }

    /**
     * Initialize leave balance for a year.
     */
    public function initializeLeaveBalance(
        int $employeeId,
        int $year,
        float $entitledDays,
        float $carriedForward = 0
    ): LeaveBalance {
        $employee = Employee::findOrFail($employeeId);

        $existing = LeaveBalance::where('employee_id', $employeeId)
            ->where('year', $year)
            ->first();

        if ($existing) {
            throw new InvalidArgumentException("Leave balance for {$year} already exists.");
        }

        return LeaveBalance::create([
            'account_id' => $employee->account_id,
            'business_id' => $employee->business_id,
            'employee_id' => $employeeId,
            'year' => $year,
            'entitled_days' => $entitledDays,
            'taken_days' => 0,
            'carried_forward' => $carriedForward,
        ]);
    }

    /**
     * Deduct leave days when taken.
     */
    public function deductLeave(int $employeeId, string $date, float $days): void
    {
        $year = Carbon::parse($date)->year;

        $balance = LeaveBalance::where('employee_id', $employeeId)
            ->where('year', $year)
            ->first();

        if (! $balance) {
            throw new InvalidArgumentException("No leave balance found for {$year} — initialize it first.");
        }

        $balance->taken_days = round((float) $balance->taken_days + $days, 2);
        $balance->save();
    }

    /**
     * Mark someone as left.
     */
    public function terminate(int $employeeId, string $leftOn, ?string $reason = null): Employee
    {
        $employee = Employee::findOrFail($employeeId);

        if ($employee->status === 'left') {
            throw new InvalidArgumentException('This employee has already left.');
        }

        $employee->update([
            'status' => 'left',
            'left_on' => $leftOn,
            'notes' => $reason ? ($employee->notes ? $employee->notes . "\n\n" . $reason : $reason) : $employee->notes,
        ]);

        return $employee;
    }

    /**
     * Reporting hierarchy — who reports to whom?
     */
    public function reportingTree(int $businessId): array
    {
        $employees = Employee::where('business_id', $businessId)
            ->with(['manager', 'position', 'department'])
            ->active()
            ->get();

        $tree = [];
        $byId = $employees->keyBy('id');

        foreach ($employees as $emp) {
            if (! $emp->manager_id) {
                // Top level
                $tree[] = $this->buildNode($emp, $byId);
            }
        }

        return $tree;
    }

    private function buildNode(Employee $employee, $allEmployees): array
    {
        return [
            'id' => $employee->id,
            'name' => $employee->name,
            'position' => $employee->position?->title,
            'department' => $employee->department?->name,
            'reports' => $employee->reports()
                ->active()
                ->get()
                ->map(fn ($r) => $this->buildNode($r, $allEmployees))
                ->toArray(),
        ];
    }

    /**
     * Attendance summary for a period.
     *
     * @return array{present: int, absent: int, on_leave: int, total_hours: float}
     */
    public function attendanceSummary(int $employeeId, string $from, string $to): array
    {
        $records = Attendance::where('employee_id', $employeeId)
            ->forPeriod($from, $to)
            ->get();

        return [
            'present' => $records->where('status', 'present')->count(),
            'absent' => $records->where('status', 'absent')->count(),
            'on_leave' => $records->where('status', 'on_leave')->count(),
            'total_hours' => round($records->sum('hours_worked'), 2),
            'overtime_hours' => round($records->sum('hours_overtime'), 2),
        ];
    }
}
