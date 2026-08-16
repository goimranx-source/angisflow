<?php

declare(strict_types=1);

namespace App\Http\Api\V1;

use App\Domain\HR\HRService;
use App\Domain\Tenancy\TenantContext;
use App\Http\Api\Endpoint;
use App\Models\Attendance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Attendance, and leave, wrapping HRService.
 *
 * ── Why there is no separate leave_requests table ─────────────────────────────
 *
 * The schema has no request/approval workflow for leave — only `attendances`
 * (a day marked `on_leave`, with a `leave_type`) and `leave_balances`
 * (entitlement and usage, by year). There is nowhere to persist "pending",
 * nor a rejection. `leaveRequests()` below reads leave out of `attendances`
 * and reports each day as its own request, `approved` when the record already
 * carries `approved_at` and `pending` otherwise — an honest reading of what
 * the schema actually holds, not a stand-in for a workflow that would need
 * its own table (and its own migration) to build properly. The frontend's
 * approve/reject buttons are unwired stubs on the page itself, so nothing
 * here is asked to make them work yet.
 */
class AttendanceController extends Endpoint
{
    public function __construct(
        private readonly HRService $hr,
        private readonly TenantContext $tenant,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $business = $this->requireBusiness();

        $query = Attendance::where('business_id', $business->id)->with('employee');

        if ($search = trim((string) $request->get('search', ''))) {
            $query->whereHas('employee', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%");
            });
        }

        if ($from = $request->get('date_from')) {
            $query->whereDate('date', '>=', $from);
        }

        if ($to = $request->get('date_to')) {
            $query->whereDate('date', '<=', $to);
        }

        // The screen's `late` has no column of its own — it is derived from
        // clock_in versus a 9am expectation, so filtering on it is done in
        // memory rather than as a WHERE the database can answer directly.
        $status = $request->get('status');
        if ($status && $status !== 'late') {
            $query->where('status', $status);
        }

        $sortColumn = $request->get('sort_by') === 'date' || $request->get('sort_by') === null ? 'date' : 'date';
        $sortDirection = $request->get('sort_direction') === 'asc' ? 'asc' : 'desc';

        $perPage = min(max((int) $request->get('per_page', 50), 1), 200);
        $page = $query->orderBy($sortColumn, $sortDirection)->paginate($perPage);

        $records = $page->getCollection()
            ->map(fn (Attendance $a) => $this->present($a))
            ->when($status === 'late', fn ($c) => $c->filter(fn ($r) => $r['status'] === 'late'))
            ->values();

        $today = now()->toDateString();
        $todayRecords = Attendance::where('business_id', $business->id)->whereDate('date', $today)->get();
        $periodRecords = (clone $query)->get();

        return response()->json([
            'data' => $records->all(),
            'summary' => [
                'present_today' => $todayRecords->where('status', 'present')->count(),
                'absent_today' => $todayRecords->where('status', 'absent')->count(),
                'on_leave_today' => $todayRecords->where('status', 'on_leave')->count(),
                'avg_hours' => round((float) $periodRecords->avg('hours_worked'), 1) ?: 0.0,
            ],
            'meta' => [
                'total' => $page->total(),
                'per_page' => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    /** One record per employee per day — HRService::recordAttendance() owns the rule. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => 'required|integer',
            'date' => 'required|date',
            'clock_in' => 'nullable|date_format:H:i,H:i:s',
            'clock_out' => 'nullable|date_format:H:i,H:i:s',
            'status' => 'nullable|in:present,absent,on_leave,holiday,half_day',
            'leave_type' => 'nullable|in:sick,annual,unpaid,other',
            'hours_worked' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        $employeeId = $data['employee_id'];
        unset($data['employee_id']);

        try {
            $attendance = $this->hr->recordAttendance($employeeId, $data);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->present($attendance->load('employee'))], 201);
    }

    public function clockIn(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => 'required|integer',
            'date' => 'nullable|date',
            'lat' => 'nullable|string',
            'lng' => 'nullable|string',
        ]);

        try {
            $attendance = $this->hr->clockIn($data['employee_id'], $data['date'] ?? null, null, $data['lat'] ?? null, $data['lng'] ?? null);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->present($attendance->load('employee'))]);
    }

    public function clockOut(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => 'required|integer',
            'date' => 'nullable|date',
            'lat' => 'nullable|string',
            'lng' => 'nullable|string',
        ]);

        try {
            $attendance = $this->hr->clockOut($data['employee_id'], $data['date'] ?? null, $data['lat'] ?? null, $data['lng'] ?? null);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->present($attendance->load('employee'))]);
    }

    /** See the class docblock — synthesised from `attendances`, not a real workflow table. */
    public function leaveRequests(Request $request): JsonResponse
    {
        $business = $this->requireBusiness();

        $query = Attendance::where('business_id', $business->id)
            ->where('status', 'on_leave')
            ->with(['employee', 'approvedBy']);

        if ($search = trim((string) $request->get('search', ''))) {
            $query->whereHas('employee', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%");
            });
        }

        if ($status = $request->get('status')) {
            if ($status === 'approved') {
                $query->whereNotNull('approved_at');
            } elseif ($status === 'pending') {
                $query->whereNull('approved_at');
            } elseif ($status === 'rejected') {
                // No record can ever be in this state — see the class docblock.
                $query->whereRaw('1 = 0');
            }
        }

        $perPage = min(max((int) $request->get('per_page', 50), 1), 200);
        $page = $query->orderByDesc('date')->paginate($perPage);

        return response()->json([
            'data' => $page->getCollection()->map(fn (Attendance $a) => $this->presentLeave($a))->all(),
            'meta' => [
                'total' => $page->total(),
                'per_page' => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    // ── Helpers ────────────────────────────────────────────────────────

    private function present(Attendance $a): array
    {
        return [
            'id' => $a->public_id,
            'employee' => [
                'id' => $a->employee->public_id,
                'name' => $a->employee->name,
                'employee_id' => $a->employee->code ?? ('EMP' . $a->employee->id),
            ],
            'date' => $a->date->toDateString(),
            'check_in' => $a->clock_in,
            'check_out' => $a->clock_out,
            'status' => $a->status === 'present' && $a->isLate() ? 'late' : $a->status,
            'hours_worked' => $a->hours_worked !== null ? (float) $a->hours_worked : null,
            'overtime_hours' => $a->hours_overtime !== null ? (float) $a->hours_overtime : null,
            'notes' => $a->notes,
            'created_at' => $a->created_at?->toIso8601String(),
        ];
    }

    private function presentLeave(Attendance $a): array
    {
        return [
            'id' => $a->public_id,
            'employee' => [
                'id' => $a->employee->public_id,
                'name' => $a->employee->name,
                'employee_id' => $a->employee->code ?? ('EMP' . $a->employee->id),
            ],
            'leave_type' => $a->leave_type === 'other' ? 'personal' : ($a->leave_type ?? 'annual'),
            'start_date' => $a->date->toDateString(),
            'end_date' => $a->date->toDateString(),
            'days_count' => 1,
            'status' => $a->approved_at ? 'approved' : 'pending',
            'reason' => $a->notes,
            'approver' => $a->approvedBy ? ['id' => (string) $a->approvedBy->id, 'name' => $a->approvedBy->name] : null,
            'approved_at' => $a->approved_at?->toIso8601String(),
            'created_at' => $a->created_at?->toIso8601String(),
        ];
    }

    private function requireBusiness()
    {
        $business = $this->tenant->business();

        if (! $business) {
            throw new RuntimeException('No business is open — attendance records belong to one set of books.');
        }

        return $business;
    }
}
