<?php

namespace App\Domain\FieldService;

use App\Domain\Sales\Models\Customer;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Tenancy\TenantContext;
use App\Models\WorkOrder;
use App\Models\CustomerAsset;
use App\Models\FleetVehicle;
use App\Models\Employee;
use App\Models\ServiceContract;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Service for managing field service work orders
 *
 * Handles the complete lifecycle of field service work orders from creation
 * to completion, including scheduling, assignment, and performance tracking.
 */
class WorkOrderService
{
    /** How many times to retry a work order number collision before refusing. */
    private const MAX_NUMBER_ATTEMPTS = 3;

    /**
     * Create a new work order
     *
     * WorkOrder::generateWorkOrderNumber() has no locking — two requests
     * landing in the same instant can both read the same "last number" and
     * both try to insert the next one. The unique index on
     * work_order_number is the real guard; this retries the whole attempt
     * with a freshly generated number when it fires, so the caller sees a
     * created work order instead of a raw database error.
     */
    public function createWorkOrder(array $data): WorkOrder
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->attemptCreateWorkOrder($data);
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                if ($attempt >= self::MAX_NUMBER_ATTEMPTS) {
                    throw new \RuntimeException(
                        'This work order could not be created right now — please try again in a moment.',
                        previous: $e
                    );
                }
                // Somebody else claimed that work order number between our
                // read and our insert; generate a new one and try again.
            }
        }
    }

    private function attemptCreateWorkOrder(array $data): WorkOrder
    {
        return DB::transaction(function () use ($data) {
            $tenant = app(TenantContext::class);
            
            // Validate customer exists
            $customer = Customer::where('business_id', $tenant->business()->id)
                               ->where('id', $data['customer_id'])
                               ->firstOrFail();

            // Validate asset if specified
            if (isset($data['customer_asset_id'])) {
                $asset = CustomerAsset::where('business_id', $tenant->business()->id)
                                     ->where('id', $data['customer_asset_id'])
                                     ->where('customer_id', $customer->id)
                                     ->firstOrFail();
            }

            // Calculate scheduled end time if not provided
            if (!isset($data['scheduled_end']) && isset($data['scheduled_start']) && isset($data['estimated_duration_minutes'])) {
                $scheduledStart = new \DateTime($data['scheduled_start']);
                $data['scheduled_end'] = $scheduledStart->copy()->addMinutes($data['estimated_duration_minutes']);
            }

            // Set default values
            $workOrderData = array_merge($data, [
                'account_id' => $tenant->account()->id,
                'business_id' => $tenant->business()->id,
                'work_order_number' => WorkOrder::generateWorkOrderNumber(),
                'status' => $data['status'] ?? 'created',
                'currency' => $tenant->business()->base_currency,
                'estimated_cost_minor' => $data['estimated_cost_minor'] ?? 0,
                'is_billable' => $data['is_billable'] ?? true,
                'is_warranty' => $data['is_warranty'] ?? false,
                'source' => $data['source'] ?? 'internal',
                'created_by' => auth()->id(),
            ]);

            return WorkOrder::create($workOrderData);
        });
    }

    /**
     * Update work order
     */
    public function updateWorkOrder(WorkOrder $workOrder, array $data): WorkOrder
    {
        if ($workOrder->status === 'completed') {
            throw new \InvalidArgumentException('Cannot update completed work orders');
        }

        $workOrder->update($data);
        return $workOrder->fresh();
    }

    /**
     * Assign work order to technician and vehicle
     */
    public function assignWorkOrder(WorkOrder $workOrder, ?int $technicianId = null, ?int $vehicleId = null): WorkOrder
    {
        return $workOrder->assignTo($technicianId, $vehicleId, auth()->id());
    }

    /**
     * Start work order
     */
    public function startWorkOrder(WorkOrder $workOrder): WorkOrder
    {
        return $workOrder->start(auth()->id());
    }

    /**
     * Complete work order
     */
    public function completeWorkOrder(WorkOrder $workOrder, array $completionData): WorkOrder
    {
        return $workOrder->complete($completionData, auth()->id());
    }

    /**
     * Cancel work order
     */
    public function cancelWorkOrder(WorkOrder $workOrder, ?string $reason = null): WorkOrder
    {
        return $workOrder->cancel($reason, auth()->id());
    }

    /**
     * Reschedule work order
     */
    public function rescheduleWorkOrder(WorkOrder $workOrder, \DateTimeInterface $newStart, \DateTimeInterface $newEnd, ?string $reason = null): WorkOrder
    {
        return $workOrder->reschedule($newStart, $newEnd, $reason);
    }

    /**
     * Get work orders for a date range
     */
    public function getWorkOrdersForPeriod(\DateTimeInterface $start, \DateTimeInterface $end, array $filters = []): Collection
    {
        $tenant = app(TenantContext::class);
        
        $query = WorkOrder::where('business_id', $tenant->business()->id)
                          ->with(['customer', 'customerAsset', 'assignedTechnician', 'assignedVehicle'])
                          ->scheduledBetween($start, $end);

        // Apply filters
        if (isset($filters['status'])) {
            $query->whereIn('status', (array) $filters['status']);
        }

        if (isset($filters['work_order_type'])) {
            $query->where('work_order_type', $filters['work_order_type']);
        }

        if (isset($filters['priority'])) {
            $query->whereIn('priority', (array) $filters['priority']);
        }

        if (isset($filters['technician_id'])) {
            $query->where('assigned_technician_id', $filters['technician_id']);
        }

        if (isset($filters['customer_id'])) {
            $query->where('customer_id', $filters['customer_id']);
        }

        if (isset($filters['billable'])) {
            $query->where('is_billable', $filters['billable']);
        }

        return $query->orderBy('scheduled_start')->get();
    }

    /**
     * Get unassigned work orders
     */
    public function getUnassignedWorkOrders(): Collection
    {
        $tenant = app(TenantContext::class);
        
        return WorkOrder::where('business_id', $tenant->business()->id)
                        ->unassigned()
                        ->whereIn('status', ['created'])
                        ->with(['customer', 'customerAsset'])
                        ->orderBy('priority', 'desc')
                        ->orderBy('requested_date')
                        ->get();
    }

    /**
     * Get overdue work orders
     */
    public function getOverdueWorkOrders(): Collection
    {
        $tenant = app(TenantContext::class);
        
        return WorkOrder::where('business_id', $tenant->business()->id)
                        ->overdue()
                        ->with(['customer', 'assignedTechnician'])
                        ->orderBy('scheduled_end')
                        ->get();
    }

    /**
     * Get today's work orders for a technician
     */
    public function getTodaysWorkOrders(int $technicianId): Collection
    {
        $tenant = app(TenantContext::class);
        
        return WorkOrder::where('business_id', $tenant->business()->id)
                        ->assignedTo($technicianId)
                        ->today()
                        ->whereIn('status', ['assigned', 'in_progress'])
                        ->with(['customer', 'customerAsset'])
                        ->orderBy('scheduled_start')
                        ->get();
    }

    /**
     * Get work order statistics
     */
    public function getWorkOrderStats(\DateTimeInterface $start, \DateTimeInterface $end): array
    {
        $tenant = app(TenantContext::class);
        
        $workOrders = WorkOrder::where('business_id', $tenant->business()->id)
                              ->scheduledBetween($start, $end)
                              ->get();

        $totalOrders = $workOrders->count();
        $completedOrders = $workOrders->where('status', 'completed')->count();
        $cancelledOrders = $workOrders->where('status', 'cancelled')->count();
        $overdueOrders = $workOrders->filter->isOverdue()->count();

        // Calculate revenue from completed billable work orders
        $totalRevenue = $workOrders
            ->where('status', 'completed')
            ->where('is_billable', true)
            ->sum(function ($wo) {
                return $wo->getEffectiveCost()->minor;
            });

        // Calculate average completion time
        $completedWithTimes = $workOrders
            ->where('status', 'completed')
            ->filter(fn($wo) => $wo->actual_start && $wo->actual_end);

        $avgCompletionMinutes = $completedWithTimes->isEmpty() 
            ? 0 
            : $completedWithTimes->avg(fn($wo) => $wo->getActualDurationMinutes());

        $currency = $tenant->business()->base_currency;

        return [
            'period' => [
                'from' => $start->format('Y-m-d'),
                'to' => $end->format('Y-m-d'),
            ],
            'total_work_orders' => $totalOrders,
            'completed_orders' => $completedOrders,
            'cancelled_orders' => $cancelledOrders,
            'overdue_orders' => $overdueOrders,
            'completion_rate' => $totalOrders > 0 ? round(($completedOrders / $totalOrders) * 100, 1) : 0,
            'cancellation_rate' => $totalOrders > 0 ? round(($cancelledOrders / $totalOrders) * 100, 1) : 0,
            'total_revenue' => new Money($totalRevenue, $currency),
            'average_order_value' => $completedOrders > 0 
                ? new Money((int) round($totalRevenue / $completedOrders), $currency)
                : new Money(0, $currency),
            'average_completion_time_hours' => round($avgCompletionMinutes / 60, 1),
        ];
    }

    /**
     * Get technician performance metrics
     */
    public function getTechnicianPerformance(int $technicianId, \DateTimeInterface $start, \DateTimeInterface $end): array
    {
        $tenant = app(TenantContext::class);
        
        $workOrders = WorkOrder::where('business_id', $tenant->business()->id)
                              ->where('assigned_technician_id', $technicianId)
                              ->scheduledBetween($start, $end)
                              ->get();

        $totalOrders = $workOrders->count();
        $completedOrders = $workOrders->where('status', 'completed')->count();
        $onTimeOrders = $workOrders->filter(function ($wo) {
            return $wo->status === 'completed' && 
                   $wo->actual_end && 
                   $wo->scheduled_end && 
                   $wo->actual_end <= $wo->scheduled_end;
        })->count();

        $avgSatisfaction = $workOrders
            ->where('status', 'completed')
            ->whereNotNull('customer_satisfaction')
            ->avg('customer_satisfaction');

        $totalRevenue = $workOrders
            ->where('status', 'completed')
            ->where('is_billable', true)
            ->sum(fn($wo) => $wo->getEffectiveCost()->minor);

        return [
            'technician_id' => $technicianId,
            'period' => [
                'from' => $start->format('Y-m-d'),
                'to' => $end->format('Y-m-d'),
            ],
            'total_orders' => $totalOrders,
            'completed_orders' => $completedOrders,
            'completion_rate' => $totalOrders > 0 ? round(($completedOrders / $totalOrders) * 100, 1) : 0,
            'on_time_rate' => $completedOrders > 0 ? round(($onTimeOrders / $completedOrders) * 100, 1) : 0,
            'average_satisfaction' => $avgSatisfaction ? round($avgSatisfaction, 1) : null,
            'total_revenue' => new Money($totalRevenue, $tenant->business()->base_currency),
        ];
    }

    /**
     * Auto-assign work orders to available technicians
     */
    public function autoAssignWorkOrders(): array
    {
        $tenant = app(TenantContext::class);
        $assignments = [];

        // Get unassigned work orders
        $unassignedOrders = $this->getUnassignedWorkOrders();

        // Get available technicians (those with vehicles and no current assignments)
        // position.jobLevel is eager-loaded here because findOptimalTechnician()
        // below reads it to score seniority — Employee has no "job_level"
        // relation of its own, only position()->jobLevel().
        $availableTechnicians = Employee::where('business_id', $tenant->business()->id)
                                       ->where('status', 'active')
                                       ->whereHas('vehicleAssignments', function ($q) {
                                           $q->where('is_active', true);
                                       })
                                       ->with('position.jobLevel')
                                       ->get();

        foreach ($unassignedOrders->take(10) as $workOrder) {
            // Find best technician based on skills, location, availability
            $bestTechnician = $this->findOptimalTechnician($workOrder, $availableTechnicians);
            
            if ($bestTechnician) {
                $vehicle = $bestTechnician->vehicleAssignments()
                                         ->where('is_active', true)
                                         ->with('vehicle')
                                         ->first()
                                         ?->vehicle;

                $this->assignWorkOrder($workOrder, $bestTechnician->id, $vehicle?->id);
                
                $assignments[] = [
                    'work_order_id' => $workOrder->id,
                    'work_order_number' => $workOrder->work_order_number,
                    'technician_id' => $bestTechnician->id,
                    'technician_name' => $bestTechnician->name,
                    'vehicle_id' => $vehicle?->id,
                ];
            }
        }

        return $assignments;
    }

    /**
     * Create work order from service contract
     */
    public function createFromContract(ServiceContract $contract, int $assetId, array $additionalData = []): WorkOrder
    {
        $asset = CustomerAsset::where('id', $assetId)
                             ->where('customer_id', $contract->customer_id)
                             ->firstOrFail();

        $workOrderData = array_merge($additionalData, [
            'title' => "Scheduled maintenance - {$asset->name}",
            'description' => "Scheduled maintenance as per service contract {$contract->contract_number}",
            'work_order_type' => 'maintenance',
            'priority' => 'medium',
            'customer_id' => $contract->customer_id,
            'customer_asset_id' => $asset->id,
            'service_address' => $asset->installation_address,
            'contact_name' => $asset->installation_contact,
            'contact_phone' => $asset->installation_phone,
            'is_billable' => false, // Covered by contract
            'source' => 'contract',
        ]);

        return $this->createWorkOrder($workOrderData);
    }

    /**
     * Generate recurring work orders from contracts
     */
    public function generateRecurringWorkOrders(): array
    {
        $tenant = app(TenantContext::class);
        $generatedOrders = [];

        // Get active contracts that need service
        $contracts = ServiceContract::where('business_id', $tenant->business()->id)
                                   ->active()
                                   ->where('next_service_date', '<=', now()->addDays(7))
                                   ->get();

        foreach ($contracts as $contract) {
            foreach ($contract->covered_assets as $assetId) {
                try {
                    $workOrder = $this->createFromContract($contract, $assetId, [
                        'scheduled_start' => $contract->next_service_date . ' 09:00:00',
                    ]);

                    $generatedOrders[] = $workOrder;

                    // Update contract next service date
                    $contract->update([
                        'next_service_date' => now()->addMonth()->toDateString(),
                    ]);

                } catch (\Exception $e) {
                    // Log error but continue with other contracts
                    \Log::warning("Failed to create work order from contract {$contract->contract_number}: " . $e->getMessage());
                }
            }
        }

        return $generatedOrders;
    }

    // ── Private Helper Methods ─────────────────────────────────────────────

    /**
     * Find optimal technician for a work order
     */
    private function findOptimalTechnician(WorkOrder $workOrder, Collection $availableTechnicians): ?Employee
    {
        if ($availableTechnicians->isEmpty()) {
            return null;
        }

        // Simple scoring algorithm
        $scored = $availableTechnicians->map(function ($technician) use ($workOrder) {
            $score = 0;

            // Check current workload (prefer less busy technicians)
            $todaysOrders = WorkOrder::where('assigned_technician_id', $technician->id)
                                   ->today()
                                   ->whereIn('status', ['assigned', 'in_progress'])
                                   ->count();
            
            $score += max(0, 10 - $todaysOrders); // Prefer less busy

            // Priority bonus for urgent orders
            if ($workOrder->priority === 'urgent') {
                $score += 5;
            }

            // Experience bonus (simplified - could check skills/certifications)
            if ($technician->position?->jobLevel && $technician->position->jobLevel->name === 'Senior') {
                $score += 3;
            }

            return [
                'technician' => $technician,
                'score' => $score,
            ];
        })->sortByDesc('score');

        return $scored->first()['technician'] ?? null;
    }
}