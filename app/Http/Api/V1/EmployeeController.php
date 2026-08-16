<?php

declare(strict_types=1);

namespace App\Http\Api\V1;

use App\Domain\HR\HRService;
use App\Domain\Tenancy\TenantContext;
use App\Http\Api\Endpoint;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Employees, wrapping HRService.
 *
 * ── Why the response does not mirror the `employees` table 1:1 ───────────────
 *
 * The employee record is built for payroll: one `name`, a `pay_type` (how they
 * are paid), `base_salary`. The employee directory screen was designed against
 * a more generic HR shape (first/last name, an `employment_type` of
 * full_time/part_time/contract/intern). The two do not name the same things —
 * `pay_type` says how someone is paid, not what kind of contract they are on —
 * so `employment_type` cannot be derived from this table and is reported as
 * `full_time` for everyone until that concept actually exists on `employees`.
 * Everything else below is translated as faithfully as the schema allows.
 */
class EmployeeController extends Endpoint
{
    public function __construct(
        private readonly HRService $hr,
        private readonly TenantContext $tenant,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $business = $this->requireBusiness();

        $query = Employee::where('business_id', $business->id)
            ->with(['department', 'position', 'manager']);

        if ($search = trim((string) $request->get('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        if ($department = $request->get('department')) {
            $query->where(function ($q) use ($department) {
                $q->where('department_id', $department)
                    ->orWhereHas('department', fn ($dq) => $dq->where('code', $department));
            });
        }

        // The screen's three statuses collapse two of ours (`suspended`,
        // `left`) into one `terminated` — see statusForApi().
        if ($status = $request->get('status')) {
            if ($status === 'terminated') {
                $query->whereIn('status', ['suspended', 'left']);
            } else {
                $query->where('status', $status);
            }
        }

        $sortColumn = match ($request->get('sort_by')) {
            'hire_date' => 'joined_on',
            'full_name' => 'name',
            default => 'name',
        };
        $sortDirection = $request->get('sort_direction') === 'desc' ? 'desc' : 'asc';

        $perPage = min(max((int) $request->get('per_page', 50), 1), 200);
        $page = $query->orderBy($sortColumn, $sortDirection)->paginate($perPage);

        $summary = [
            'total_employees' => Employee::where('business_id', $business->id)->count(),
            'active_employees' => Employee::where('business_id', $business->id)->where('status', 'active')->count(),
            'on_leave_count' => Employee::where('business_id', $business->id)->where('status', 'on_leave')->count(),
            'new_this_month' => Employee::where('business_id', $business->id)
                ->whereDate('joined_on', '>=', now()->startOfMonth())
                ->count(),
        ];

        return response()->json([
            'data' => $page->getCollection()->map(fn (Employee $e) => $this->present($e))->all(),
            'summary' => $summary,
            'meta' => [
                'total' => $page->total(),
                'per_page' => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function show(string $publicId): JsonResponse
    {
        $employee = $this->findByPublicId($publicId);

        return response()->json(['data' => $this->present($employee, detailed: true)]);
    }

    public function store(Request $request): JsonResponse
    {
        $business = $this->requireBusiness();

        $data = $request->validate([
            'name' => 'nullable|string|max:150',
            'first_name' => 'nullable|string|max:75',
            'last_name' => 'nullable|string|max:75',
            'email' => 'nullable|email|max:150',
            'phone' => 'nullable|string|max:30',
            'department_id' => 'nullable|integer',
            'position_id' => 'nullable|integer',
            'manager_id' => 'nullable|integer',
            'hire_date' => 'nullable|date',
            'joined_on' => 'nullable|date',
            'date_of_birth' => 'nullable|date',
            'base_salary' => 'nullable|numeric|min:0',
            'salary' => 'nullable|numeric|min:0',
            'pay_type' => 'nullable|in:monthly,daily,hourly',
            'payment_method' => 'nullable|in:bank,cash,mobile',
            'status' => 'nullable|in:active,on_leave,suspended,left',
            'code' => 'nullable|string|max:30',
        ]);

        $name = $data['name'] ?? trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? ''));

        if ($name === '') {
            return response()->json(['message' => 'A name is required — either `name`, or `first_name`/`last_name`.'], 422);
        }

        try {
            $employee = $this->hr->hire($business->id, [
                'name' => $name,
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'department_id' => $data['department_id'] ?? null,
                'position_id' => $data['position_id'] ?? null,
                'manager_id' => $data['manager_id'] ?? null,
                'joined_on' => $data['joined_on'] ?? $data['hire_date'] ?? null,
                'date_of_birth' => $data['date_of_birth'] ?? null,
                'base_salary' => $data['base_salary'] ?? $data['salary'] ?? 0,
                'pay_type' => $data['pay_type'] ?? 'monthly',
                'payment_method' => $data['payment_method'] ?? 'bank',
                'status' => $data['status'] ?? 'active',
                'code' => $data['code'] ?? null,
            ]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->present($employee, detailed: true)], 201);
    }

    public function update(Request $request, string $publicId): JsonResponse
    {
        $employee = $this->findByPublicId($publicId);

        $data = $request->validate([
            'name' => 'nullable|string|max:150',
            'email' => 'nullable|email|max:150',
            'phone' => 'nullable|string|max:30',
            'department_id' => 'nullable|integer',
            'position_id' => 'nullable|integer',
            'manager_id' => 'nullable|integer',
            'base_salary' => 'nullable|numeric|min:0',
            'pay_type' => 'nullable|in:monthly,daily,hourly',
            'payment_method' => 'nullable|in:bank,cash,mobile',
            'status' => 'nullable|in:active,on_leave,suspended,left',
        ]);

        $employee->update($data);

        return response()->json(['data' => $this->present($employee->fresh(), detailed: true)]);
    }

    /** Terminate rather than delete — HR records are kept, not removed. */
    public function destroy(Request $request, string $publicId): JsonResponse
    {
        $employee = $this->findByPublicId($publicId);

        try {
            $this->hr->terminate($employee->id, $request->get('left_on', now()->toDateString()), $request->get('reason'));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->present($employee->fresh())]);
    }

    // ── Helpers ────────────────────────────────────────────────────────

    private function present(Employee $employee, bool $detailed = false): array
    {
        [$firstName, $lastName] = $this->splitName($employee->name);

        $out = [
            'id' => $employee->public_id,
            'employee_id' => $employee->code ?? ('EMP' . $employee->id),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'full_name' => $employee->name,
            'email' => $employee->email,
            'phone' => $employee->phone,
            'department' => $employee->department ? [
                'id' => (string) $employee->department->id,
                'name' => $employee->department->name,
            ] : null,
            'role' => $employee->position?->title ?? '—',
            // See the class docblock: not a concept this table has yet.
            'employment_type' => 'full_time',
            'status' => $this->statusForApi($employee->status),
            'hire_date' => $employee->joined_on?->toDateString(),
            'date_of_birth' => $employee->date_of_birth?->toDateString(),
            'salary' => $employee->base_salary !== null ? (float) $employee->base_salary : null,
            'created_at' => $employee->created_at?->toIso8601String(),
        ];

        if ($detailed) {
            $out['reports_to'] = $employee->manager ? [
                'id' => $employee->manager->public_id,
                'name' => $employee->manager->name,
            ] : null;
        }

        return $out;
    }

    /** active/on_leave stay themselves; suspended and left both read as terminated. */
    private function statusForApi(string $status): string
    {
        return match ($status) {
            'active' => 'active',
            'on_leave' => 'on_leave',
            default => 'terminated',
        };
    }

    /** @return array{0: string, 1: string} */
    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name), 2) ?: [$name];

        return [$parts[0] ?? $name, $parts[1] ?? ''];
    }

    private function findByPublicId(string $publicId): Employee
    {
        $business = $this->requireBusiness();

        return Employee::where('business_id', $business->id)
            ->where('public_id', $publicId)
            ->with(['department', 'position', 'manager'])
            ->firstOrFail();
    }

    private function requireBusiness()
    {
        $business = $this->tenant->business();

        if (! $business) {
            throw new RuntimeException('No business is open — employee records belong to one set of books.');
        }

        return $business;
    }
}
