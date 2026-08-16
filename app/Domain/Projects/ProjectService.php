<?php

namespace App\Domain\Projects;

use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Tenancy\TenantContext;
use App\Models\Employee;
use App\Models\Project;
use App\Models\ProjectBudgetRevision;
use App\Models\ProjectEmployeeRate;
use App\Models\TimeEntry;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Project management and configuration.
 *
 * Handles:
 * - Project lifecycle (create, update, status changes)
 * - Budget management and revisions
 * - Employee rate configuration
 * - Project reporting and analytics
 *
 * ## Project workflow
 * planning → active → completed/cancelled
 * (on_hold can occur from any active state)
 *
 * ## Budget management
 * - Time budget: estimated hours for completion
 * - Financial budget: maximum billable amount
 * - Both can be revised with audit trail
 *
 * ## Rate configuration
 * - Default project rate applies to all employees
 * - Employee-specific rates override default
 * - Rates are dated for historical accuracy
 */
class ProjectService
{
    public function __construct(
        private readonly TenantContext $tenant,
    ) {}

    /**
     * Create a new project.
     */
    public function create(array $data): Project
    {
        $business = $this->requireBusiness();

        // Auto-generate code if not provided
        if (empty($data['code'])) {
            $prefix = $data['customer_id'] ? 'CLT' : 'INT'; // Client or Internal
            $data['code'] = Project::nextCode($business->id, $prefix);
        }

        // Fill tenancy
        $data['account_id'] = $this->tenant->account()->id;
        $data['business_id'] = $business->id;

        // Set default currency
        $data['currency'] = $data['currency'] ?? $business->base_currency;

        return DB::transaction(function () use ($data) {
            $project = Project::create($data);

            // Create initial budget revision if budget is set
            if (isset($data['budget_hours']) || isset($data['budget_amount_minor'])) {
                $this->recordBudgetRevision(
                    $project,
                    $data['budget_hours'] ?? null,
                    isset($data['budget_amount_minor']) 
                        ? new Money($data['budget_amount_minor'], $project->currency)
                        : null,
                    'Initial budget',
                    $data['start_date'] ?? now()->toDateString(),
                    auth()->id()
                );
            }

            return $project;
        });
    }

    /**
     * Update project details.
     */
    public function update(Project $project, array $data): Project
    {
        return DB::transaction(function () use ($project, $data) {
            // Check for budget changes
            $budgetChanged = false;
            $newHours = $data['budget_hours'] ?? $project->budget_hours;
            $newAmount = isset($data['budget_amount_minor']) 
                ? new Money($data['budget_amount_minor'], $project->currency)
                : $project->getBudgetAmount();

            if ($newHours != $project->budget_hours || 
                ($newAmount && $project->getBudgetAmount() && $newAmount->minor != $project->getBudgetAmount()->minor)) {
                $budgetChanged = true;
            }

            $project->update($data);

            // Record budget revision if changed
            if ($budgetChanged && isset($data['budget_revision_reason'])) {
                $this->recordBudgetRevision(
                    $project,
                    $newHours,
                    $newAmount,
                    $data['budget_revision_reason'],
                    $data['budget_effective_from'] ?? now()->toDateString(),
                    auth()->id()
                );
            }

            return $project->fresh();
        });
    }

    /**
     * Change project status.
     */
    public function changeStatus(Project $project, string $newStatus, ?string $reason = null): Project
    {
        $validTransitions = $this->getValidStatusTransitions($project->status);

        if (!in_array($newStatus, $validTransitions)) {
            throw new RuntimeException(
                "Cannot change project status from {$project->status} to {$newStatus}."
            );
        }

        $project->update([
            'status' => $newStatus,
            'notes' => $reason ? ($project->notes ? $project->notes . "\n\n" : '') . 
                      now()->toDateString() . ": Status changed to {$newStatus}. {$reason}" 
                      : $project->notes,
        ]);

        return $project->fresh();
    }

    /**
     * Set employee-specific rate for a project.
     */
    public function setEmployeeRate(
        Project $project,
        Employee $employee,
        Money $rate,
        string $effectiveFrom,
        ?string $effectiveTo = null
    ): ProjectEmployeeRate {
        // Deactivate any overlapping rates
        ProjectEmployeeRate::where('project_id', $project->id)
            ->where('employee_id', $employee->id)
            ->where('is_active', true)
            ->where(function ($q) use ($effectiveFrom, $effectiveTo) {
                $q->where('effective_from', '<=', $effectiveFrom)
                  ->where(function ($sq) use ($effectiveFrom) {
                      $sq->whereNull('effective_to')
                        ->orWhere('effective_to', '>=', $effectiveFrom);
                  });
                
                if ($effectiveTo) {
                    $q->orWhere('effective_from', '<=', $effectiveTo)
                      ->where(function ($sq) use ($effectiveTo) {
                          $sq->whereNull('effective_to')
                            ->orWhere('effective_to', '>=', $effectiveTo);
                      });
                }
            })
            ->update(['is_active' => false]);

        return ProjectEmployeeRate::create([
            'account_id' => $this->tenant->account()->id,
            'business_id' => $project->business_id,
            'project_id' => $project->id,
            'employee_id' => $employee->id,
            'rate_minor' => $rate->minor,
            'effective_from' => $effectiveFrom,
            'effective_to' => $effectiveTo,
            'is_active' => true,
        ]);
    }

    /**
     * Record a budget revision.
     */
    public function recordBudgetRevision(
        Project $project,
        ?float $budgetHours,
        ?Money $budgetAmount,
        string $reason,
        string $effectiveFrom,
        int $userId
    ): ProjectBudgetRevision {
        return ProjectBudgetRevision::create([
            'account_id' => $this->tenant->account()->id,
            'business_id' => $project->business_id,
            'project_id' => $project->id,
            'budget_hours' => $budgetHours,
            'budget_amount_minor' => $budgetAmount?->minor,
            'currency' => $project->currency,
            'reason' => $reason,
            'effective_from' => $effectiveFrom,
            'revised_by_user_id' => $userId,
        ]);
    }

    /**
     * Get project summary statistics.
     */
    public function getProjectSummary(Project $project): array
    {
        $totalHours = $project->getTotalHours();
        $billableHours = $project->getBillableHours();
        $billableAmount = $project->getBillableAmount();
        $budgetUtilization = $project->getBudgetUtilization();
        $overBudget = $project->isOverBudget();

        return [
            'project_id' => $project->id,
            'code' => $project->code,
            'name' => $project->name,
            'status' => $project->status,
            'customer_name' => $project->customer?->name,
            'project_manager' => $project->projectManager?->name,
            
            // Time tracking
            'total_hours' => $totalHours,
            'billable_hours' => $billableHours,
            'non_billable_hours' => $totalHours - $billableHours,
            
            // Financial
            'billable_amount' => $billableAmount,
            'currency' => $project->currency,
            
            // Budget status
            'budget_hours' => $project->budget_hours,
            'budget_amount' => $project->getBudgetAmount(),
            'budget_utilization' => $budgetUtilization,
            'is_over_budget' => $overBudget,
            
            // Dates
            'start_date' => $project->start_date?->toDateString(),
            'end_date' => $project->end_date?->toDateString(),
            
            // Team
            'team_size' => $this->getProjectTeamSize($project),
        ];
    }

    /**
     * Get projects for employee timesheet.
     */
    public function getProjectsForEmployee(Employee $employee): array
    {
        $business = $this->requireBusiness();

        return Project::where('business_id', $business->id)
            ->where('is_active', true)
            ->whereIn('status', ['planning', 'active'])
            ->with(['customer', 'projectManager'])
            ->get()
            ->map(function ($project) use ($employee) {
                $rate = $project->getEmployeeRate($employee, now()->toDateString());
                
                return [
                    'id' => $project->id,
                    'code' => $project->code,
                    'name' => $project->name,
                    'customer_name' => $project->customer?->name,
                    'billing_type' => $project->billing_type,
                    'employee_rate' => $rate,
                    'requires_approval' => $project->requires_approval,
                ];
            })
            ->toArray();
    }

    /**
     * Get project profitability report.
     *
     * ── Turning a salary into an hourly cost ─────────────────────────────
     *
     * `base_salary` is not always an annual figure. Per the HR migration, it
     * is monthly for `pay_type = 'monthly'` (the default), a per-day rate for
     * `daily`, and already hourly for `hourly`. Treating all three as annual
     * — the previous formula divided everything by 2000 — overstated a
     * monthly employee's cost by roughly twelve times, which is enough to
     * turn a profitable project into an apparent loss.
     *
     * A standard working year (2,000 hours: 40 a week, 50 paid weeks) and a
     * standard working day (8 hours) are used to annualise and then spread a
     * monthly or daily figure. Neither is exact for every business, but both
     * are the conventional assumption and are named here rather than buried
     * in an unlabelled constant.
     */
    public function getProfitabilityReport(Project $project): array
    {
        $timeEntries = TimeEntry::where('project_id', $project->id)
            ->with('employee')
            ->get();

        $hoursPerYear = 2000;
        $hoursPerDay = 8;

        $scaleFactor = 10 ** Money::scaleFor($project->currency);
        $billableRevenue = $project->getBillableAmount()->minor;
        $totalCostMinor = 0;

        foreach ($timeEntries as $entry) {
            $employee = $entry->employee;

            if (! $employee || ! $employee->base_salary) {
                continue;
            }

            $salary = (float) $employee->base_salary;

            $hourlyCost = match ($employee->pay_type) {
                'daily' => $salary / $hoursPerDay,
                'hourly' => $salary,
                default => ($salary * 12) / $hoursPerYear, // 'monthly'
            };

            // Rounded per entry rather than truncated once at the end, so a
            // long report does not carry an accumulated fraction of a cent.
            $totalCostMinor += (int) round($hourlyCost * (float) $entry->hours * $scaleFactor);
        }

        $profitMinor = $billableRevenue - $totalCostMinor;

        // Null, not zero, when there is no revenue to take a margin of. A
        // project that has billed nothing has an undefined margin, and
        // reporting 0% invites somebody to average it in as a real result —
        // the same reason FinancialReports leaves gross_margin_pct null.
        $marginPercent = $billableRevenue > 0
            ? round(($profitMinor / $billableRevenue) * 100, 1)
            : null;

        return [
            'project' => $project->only(['id', 'code', 'name']),
            'revenue' => new Money($billableRevenue, $project->currency),
            'internal_cost' => new Money($totalCostMinor, $project->currency),
            'profit' => new Money($profitMinor, $project->currency),
            'margin_percent' => $marginPercent,
            'total_hours' => $timeEntries->sum('hours'),
            'billable_hours' => $timeEntries->where('is_billable', true)->sum('hours'),
        ];
    }

    // ── Private methods ─────────────────────────────────────────────────

    private function getValidStatusTransitions(string $currentStatus): array
    {
        return match ($currentStatus) {
            'planning' => ['active', 'on_hold', 'cancelled'],
            'active' => ['on_hold', 'completed', 'cancelled'],
            'on_hold' => ['active', 'cancelled'],
            'completed' => [], // Terminal state
            'cancelled' => [], // Terminal state
            default => [],
        };
    }

    private function getProjectTeamSize(Project $project): int
    {
        return TimeEntry::where('project_id', $project->id)
            ->distinct('employee_id')
            ->count('employee_id');
    }

    private function requireBusiness()
    {
        $business = $this->tenant->business();

        if (!$business) {
            throw new RuntimeException('No business context. Project operations require a business.');
        }

        return $business;
    }
}