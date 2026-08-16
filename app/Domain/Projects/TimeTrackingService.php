<?php

namespace App\Domain\Projects;

use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Tenancy\TenantContext;
use App\Models\Employee;
use App\Models\Project;
use App\Models\TimeEntry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Time tracking and timesheet management.
 *
 * Handles:
 * - Time entry CRUD operations
 * - Approval workflows
 * - Time calculations and validations
 * - Timesheet reporting
 *
 * ## Time entry workflow
 * draft → submitted → approved/rejected → (approved) → invoiced
 *
 * ## Billing calculation
 * When entry is approved, billable amount is calculated and locked in:
 * - Gets employee rate for project on entry date
 * - Calculates hours × rate
 * - Stores amount to prevent rate changes affecting approved time
 *
 * ## Validation rules
 * - No overlapping time entries for same employee/date
 * - Time entries must be for active projects
 * - Cannot edit invoiced entries
 * - Hours must be reasonable (0.25 to 24 per entry)
 */
class TimeTrackingService
{
    public function __construct(
        private readonly TenantContext $tenant,
    ) {}

    /**
     * Create a new time entry.
     */
    public function createTimeEntry(array $data): TimeEntry
    {
        $business = $this->requireBusiness();
        $project = Project::findOrFail($data['project_id']);
        $employee = Employee::findOrFail($data['employee_id']);

        // Validate project allows time logging
        if (!$project->canLogTime()) {
            throw new RuntimeException(
                "Project {$project->code} does not allow time logging in its current status."
            );
        }

        // Calculate hours from times if provided
        if (isset($data['start_time'], $data['end_time']) && empty($data['hours'])) {
            $data['hours'] = $this->calculateHoursFromTimes($data['start_time'], $data['end_time']);
        }

        // Validate hours
        if ($data['hours'] < 0.25 || $data['hours'] > 24) {
            throw new RuntimeException('Time entry must be between 0.25 and 24 hours.');
        }

        // Check for overlapping entries
        $this->validateNoTimeOverlap($employee, $data['entry_date'], $data['start_time'] ?? null, $data['end_time'] ?? null);

        // Fill in defaults
        $data['account_id'] = $this->tenant->account()->id;
        $data['business_id'] = $business->id;
        $data['currency'] = $project->currency;
        $data['is_billable'] = $data['is_billable'] ?? $project->isBillable();
        $data['status'] = 'draft';

        // Get rate for billing calculation (not locked until approval)
        if ($data['is_billable']) {
            $rate = $project->getEmployeeRate($employee, $data['entry_date']);
            if ($rate) {
                $data['rate_minor'] = $rate->minor;
                $data['billable_amount_minor'] = (int) round($data['hours'] * $rate->minor);
            }
        }

        return TimeEntry::create($data);
    }

    /**
     * Update time entry (only if in draft or rejected status).
     */
    public function updateTimeEntry(TimeEntry $entry, array $data): TimeEntry
    {
        if (!$entry->canEdit()) {
            throw new RuntimeException(
                "Cannot edit time entry in '{$entry->status}' status."
            );
        }

        // Recalculate hours if times changed
        if (isset($data['start_time'], $data['end_time'])) {
            $data['hours'] = $this->calculateHoursFromTimes($data['start_time'], $data['end_time']);
        }

        // Validate hours
        if (isset($data['hours']) && ($data['hours'] < 0.25 || $data['hours'] > 24)) {
            throw new RuntimeException('Time entry must be between 0.25 and 24 hours.');
        }

        // Check for overlapping entries (exclude current entry)
        if (isset($data['entry_date']) || isset($data['start_time']) || isset($data['end_time'])) {
            $this->validateNoTimeOverlap(
                $entry->employee,
                $data['entry_date'] ?? $entry->entry_date,
                $data['start_time'] ?? $entry->start_time,
                $data['end_time'] ?? $entry->end_time,
                $entry->id
            );
        }

        // Recalculate billing if relevant fields changed
        if (isset($data['hours']) || isset($data['is_billable'])) {
            $isBillable = $data['is_billable'] ?? $entry->is_billable;
            
            if ($isBillable && $entry->rate_minor) {
                $hours = $data['hours'] ?? $entry->hours;
                $data['billable_amount_minor'] = (int) round($hours * $entry->rate_minor);
            } elseif (!$isBillable) {
                $data['billable_amount_minor'] = 0;
            }
        }

        $entry->update($data);
        return $entry->fresh();
    }

    /**
     * Submit time entry for approval.
     */
    public function submitTimeEntry(TimeEntry $entry, int $userId): TimeEntry
    {
        if (!$entry->canSubmit()) {
            throw new RuntimeException(
                "Time entry cannot be submitted in '{$entry->status}' status or has no hours."
            );
        }

        $entry->submit($userId);
        return $entry->fresh();
    }

    /**
     * Approve time entry.
     */
    public function approveTimeEntry(TimeEntry $entry, int $userId, ?string $notes = null): TimeEntry
    {
        if (!$entry->canApprove()) {
            throw new RuntimeException(
                "Time entry cannot be approved in '{$entry->status}' status."
            );
        }

        // Refresh rate at approval time to ensure current rate
        if ($entry->is_billable) {
            $rate = $entry->project->getEmployeeRate($entry->employee, $entry->entry_date);
            if ($rate) {
                $entry->setRate($rate);
            }
        }

        $entry->approve($userId, $notes);
        return $entry->fresh();
    }

    /**
     * Reject time entry.
     */
    public function rejectTimeEntry(TimeEntry $entry, int $userId, string $reason): TimeEntry
    {
        if (!$entry->canApprove()) {
            throw new RuntimeException(
                "Time entry cannot be rejected in '{$entry->status}' status."
            );
        }

        $entry->reject($userId, $reason);
        return $entry->fresh();
    }

    /**
     * Bulk approve time entries.
     */
    public function bulkApproveTimeEntries(array $entryIds, int $userId, ?string $notes = null): array
    {
        $results = [];
        
        DB::transaction(function () use ($entryIds, $userId, $notes, &$results) {
            foreach ($entryIds as $entryId) {
                try {
                    // approveTimeEntry() reaches through project.getEmployeeRate()
                    // and employee — both must be eager-loaded, or lazy loading
                    // (disabled outside production) throws instead of firing an
                    // extra query per entry.
                    $entry = TimeEntry::with(['project', 'employee'])->findOrFail($entryId);
                    $this->approveTimeEntry($entry, $userId, $notes);
                    $results[] = ['id' => $entryId, 'status' => 'approved'];
                } catch (RuntimeException $e) {
                    $results[] = ['id' => $entryId, 'status' => 'failed', 'error' => $e->getMessage()];
                }
            }
        });

        return $results;
    }

    /**
     * Get employee timesheet for a period.
     */
    public function getEmployeeTimesheet(Employee $employee, string $from, string $to): array
    {
        $entries = TimeEntry::where('employee_id', $employee->id)
            ->whereBetween('entry_date', [$from, $to])
            ->with(['project.customer'])
            ->orderBy('entry_date')
            ->orderBy('start_time')
            ->get();

        // Group by date
        $timesheet = [];
        foreach ($entries as $entry) {
            $date = $entry->entry_date->toDateString();
            
            if (!isset($timesheet[$date])) {
                $timesheet[$date] = [
                    'date' => $date,
                    'entries' => [],
                    'total_hours' => 0,
                    'billable_hours' => 0,
                ];
            }

            $timesheet[$date]['entries'][] = [
                'id' => $entry->id,
                'project_code' => $entry->project->code,
                'project_name' => $entry->project->name,
                'customer_name' => $entry->project->customer?->name,
                'description' => $entry->description,
                'start_time' => $entry->start_time?->format('H:i'),
                'end_time' => $entry->end_time?->format('H:i'),
                'hours' => $entry->hours,
                'is_billable' => $entry->is_billable,
                'status' => $entry->status,
                'rate' => $entry->getRate(),
                'billable_amount' => $entry->getBillableAmount(),
            ];

            $timesheet[$date]['total_hours'] += $entry->hours;
            if ($entry->is_billable) {
                $timesheet[$date]['billable_hours'] += $entry->hours;
            }
        }

        return array_values($timesheet);
    }

    /**
     * Get pending approvals for a manager.
     */
    public function getPendingApprovals(?int $projectManagerId = null): Collection
    {
        $business = $this->requireBusiness();

        $query = TimeEntry::where('business_id', $business->id)
            ->where('status', 'submitted')
            ->with(['employee', 'project.customer', 'project.projectManager']);

        // Filter by project manager if specified
        if ($projectManagerId) {
            $query->whereHas('project', function ($q) use ($projectManagerId) {
                $q->where('project_manager_id', $projectManagerId);
            });
        }

        return $query->orderBy('submitted_at')
            ->get()
            ->map(function ($entry) {
                return [
                    'id' => $entry->id,
                    'employee_name' => $entry->employee->name,
                    'project_code' => $entry->project->code,
                    'project_name' => $entry->project->name,
                    'customer_name' => $entry->project->customer?->name,
                    'entry_date' => $entry->entry_date->toDateString(),
                    'hours' => $entry->hours,
                    'description' => $entry->description,
                    'is_billable' => $entry->is_billable,
                    'billable_amount' => $entry->getBillableAmount(),
                    'submitted_at' => $entry->submitted_at,
                ];
            });
    }

    /**
     * Get time tracking summary for period.
     */
    public function getTimeSummary(string $from, string $to, ?int $projectId = null): array
    {
        $business = $this->requireBusiness();

        $query = TimeEntry::where('business_id', $business->id)
            ->whereBetween('entry_date', [$from, $to]);

        if ($projectId) {
            $query->where('project_id', $projectId);
        }

        $entries = $query->with(['employee', 'project'])->get();

        $totalHours = $entries->sum('hours');
        $billableHours = $entries->where('is_billable', true)->sum('hours');
        $approvedBillableHours = $entries->where('is_billable', true)
            ->whereIn('status', ['approved', 'invoiced'])
            ->sum('hours');

        $totalBillableAmount = $entries->where('is_billable', true)
            ->whereIn('status', ['approved', 'invoiced'])
            ->sum('billable_amount_minor');

        return [
            'period' => ['from' => $from, 'to' => $to],
            'total_hours' => $totalHours,
            'billable_hours' => $billableHours,
            'non_billable_hours' => $totalHours - $billableHours,
            'approved_billable_hours' => $approvedBillableHours,
            'total_billable_amount' => new Money($totalBillableAmount ?: 0, $business->base_currency),
            'entries_count' => $entries->count(),
            'employees_count' => $entries->pluck('employee_id')->unique()->count(),
            'projects_count' => $entries->pluck('project_id')->unique()->count(),
        ];
    }

    // ── Private methods ─────────────────────────────────────────────────

    private function calculateHoursFromTimes(string $startTime, string $endTime): float
    {
        $start = \Carbon\Carbon::parse($startTime);
        $end = \Carbon\Carbon::parse($endTime);

        // Handle overnight work
        if ($end->lt($start)) {
            $end->addDay();
        }

        return round($start->diffInMinutes($end) / 60, 2);
    }

    private function validateNoTimeOverlap(
        Employee $employee,
        string $entryDate,
        ?string $startTime,
        ?string $endTime,
        ?int $excludeEntryId = null
    ): void {
        if (!$startTime || !$endTime) {
            return; // No overlap check for entries without specific times
        }

        $query = TimeEntry::where('employee_id', $employee->id)
            ->where('entry_date', $entryDate)
            ->whereNotNull('start_time')
            ->whereNotNull('end_time');

        if ($excludeEntryId) {
            $query->where('id', '!=', $excludeEntryId);
        }

        $existingEntries = $query->get();

        $newStart = \Carbon\Carbon::parse($startTime);
        $newEnd = \Carbon\Carbon::parse($endTime);

        foreach ($existingEntries as $existing) {
            $existingStart = \Carbon\Carbon::parse($existing->start_time);
            $existingEnd = \Carbon\Carbon::parse($existing->end_time);

            // Check for overlap
            if ($newStart->lt($existingEnd) && $newEnd->gt($existingStart)) {
                throw new RuntimeException(
                    "Time entry overlaps with existing entry from {$existing->start_time} to {$existing->end_time}."
                );
            }
        }
    }

    private function requireBusiness()
    {
        $business = $this->tenant->business();

        if (!$business) {
            throw new RuntimeException('No business context. Time tracking requires a business.');
        }

        return $business;
    }
}