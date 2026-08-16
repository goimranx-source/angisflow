<?php

namespace App\Models;

use App\Domain\Sales\Models\Customer;
use App\Domain\Tenancy\Concerns\BelongsToAccount;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A work order for field service operations
 *
 * Represents a service request that needs to be completed at a customer location,
 * including repair, maintenance, installation, or inspection work.
 */
class WorkOrder extends Model
{
    use HasFactory, BelongsToAccount, BelongsToBusiness, HasPublicId, SoftDeletes;

    protected $fillable = [
        'account_id',
        'business_id',
        'public_id',
        'work_order_number',
        'title',
        'description',
        'work_order_type',
        'priority',
        'status',
        'customer_id',
        'customer_asset_id',
        'service_address',
        'contact_name',
        'contact_phone',
        'contact_email',
        'requested_date',
        'scheduled_start',
        'scheduled_end',
        'actual_start',
        'actual_end',
        'estimated_duration_minutes',
        'assigned_technician_id',
        'assigned_vehicle_id',
        'assigned_at',
        'assigned_by',
        'estimated_cost_minor',
        'actual_cost_minor',
        'currency',
        'is_billable',
        'is_warranty',
        'work_performed',
        'parts_used',
        'technician_notes',
        'completion_signature',
        'completion_photos',
        'customer_satisfaction',
        'source',
        'special_instructions',
        'created_by',
        'completed_by',
    ];

    protected $casts = [
        'requested_date' => 'datetime',
        'scheduled_start' => 'datetime',
        'scheduled_end' => 'datetime',
        'actual_start' => 'datetime',
        'actual_end' => 'datetime',
        'assigned_at' => 'datetime',
        'is_billable' => 'boolean',
        'is_warranty' => 'boolean',
        'completion_photos' => 'array',
    ];

    public const STATUSES = [
        'created' => 'Created',
        'assigned' => 'Assigned',
        'in_progress' => 'In Progress',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ];

    public const WORK_ORDER_TYPES = [
        'repair' => 'Repair',
        'maintenance' => 'Preventive Maintenance',
        'installation' => 'Installation',
        'inspection' => 'Inspection',
        'emergency' => 'Emergency Service',
    ];

    public const PRIORITIES = [
        'low' => 'Low',
        'medium' => 'Medium',
        'high' => 'High',
        'urgent' => 'Urgent',
    ];

    public const SOURCES = [
        'internal' => 'Internal',
        'customer_portal' => 'Customer Portal',
        'phone' => 'Phone Call',
        'email' => 'Email',
        'contract' => 'Service Contract',
    ];

    // ── Relationships ──────────────────────────────────────────────────────

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function customerAsset(): BelongsTo
    {
        return $this->belongsTo(CustomerAsset::class, 'customer_asset_id');
    }

    public function assignedTechnician(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assigned_technician_id');
    }

    public function assignedVehicle(): BelongsTo
    {
        return $this->belongsTo(FleetVehicle::class, 'assigned_vehicle_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function partsUsed(): HasMany
    {
        return $this->hasMany(WorkOrderParts::class);
    }

    public function checkIns(): HasMany
    {
        return $this->hasMany(TechnicianCheckIn::class);
    }

    // ── Accessors ──────────────────────────────────────────────────────────

    /**
     * Get the estimated cost as a Money object
     */
    public function getEstimatedCost(): Money
    {
        return new Money($this->estimated_cost_minor, $this->currency);
    }

    /**
     * Get the actual cost as a Money object
     */
    public function getActualCost(): ?Money
    {
        return $this->actual_cost_minor 
            ? new Money($this->actual_cost_minor, $this->currency)
            : null;
    }

    /**
     * Get effective cost (actual or estimated)
     */
    public function getEffectiveCost(): Money
    {
        return $this->getActualCost() ?? $this->getEstimatedCost();
    }

    /**
     * Get scheduled duration in minutes
     */
    public function getScheduledDurationMinutes(): ?int
    {
        if (!$this->scheduled_start || !$this->scheduled_end) {
            return null;
        }

        return $this->scheduled_start->diffInMinutes($this->scheduled_end);
    }

    /**
     * Get actual duration in minutes
     */
    public function getActualDurationMinutes(): ?int
    {
        if (!$this->actual_start || !$this->actual_end) {
            return null;
        }

        return $this->actual_start->diffInMinutes($this->actual_end);
    }

    /**
     * Check if work order is overdue
     */
    public function isOverdue(): bool
    {
        if (!$this->scheduled_end || in_array($this->status, ['completed', 'cancelled'])) {
            return false;
        }

        return $this->scheduled_end < now();
    }

    /**
     * Check if work order is scheduled for today
     */
    public function isScheduledToday(): bool
    {
        return $this->scheduled_start && $this->scheduled_start->isToday();
    }

    /**
     * Check if work order can be started
     */
    public function canBeStarted(): bool
    {
        return $this->status === 'assigned' && $this->assigned_technician_id;
    }

    /**
     * Check if work order can be completed
     */
    public function canBeCompleted(): bool
    {
        return $this->status === 'in_progress';
    }

    /**
     * Get priority weight for sorting
     */
    public function getPriorityWeight(): int
    {
        return match($this->priority) {
            'urgent' => 4,
            'high' => 3,
            'medium' => 2,
            'low' => 1,
            default => 0,
        };
    }

    // ── Query Scopes ───────────────────────────────────────────────────────

    public function scopeStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopePriority($query, string $priority)
    {
        return $query->where('priority', $priority);
    }

    public function scopeType($query, string $workOrderType)
    {
        return $query->where('work_order_type', $workOrderType);
    }

    public function scopeAssignedTo($query, int $technicianId)
    {
        return $query->where('assigned_technician_id', $technicianId);
    }

    public function scopeUnassigned($query)
    {
        return $query->whereNull('assigned_technician_id');
    }

    public function scopeScheduledBetween($query, \DateTimeInterface $start, \DateTimeInterface $end)
    {
        return $query->where(function ($q) use ($start, $end) {
            $q->whereBetween('scheduled_start', [$start, $end])
              ->orWhereBetween('scheduled_end', [$start, $end])
              ->orWhere(function ($q2) use ($start, $end) {
                  $q2->where('scheduled_start', '<=', $start)
                     ->where('scheduled_end', '>=', $end);
              });
        });
    }

    public function scopeOverdue($query)
    {
        return $query->where('scheduled_end', '<', now())
                     ->whereNotIn('status', ['completed', 'cancelled']);
    }

    public function scopeToday($query)
    {
        return $query->whereDate('scheduled_start', now()->toDateString());
    }

    public function scopeBillable($query)
    {
        return $query->where('is_billable', true);
    }

    public function scopeWarranty($query)
    {
        return $query->where('is_warranty', true);
    }

    // ── Business Logic ─────────────────────────────────────────────────────

    /**
     * Generate unique work order number
     */
    public static function generateWorkOrderNumber(): string
    {
        $prefix = 'WO-' . date('Y') . '-';
        $lastWorkOrder = static::where('work_order_number', 'like', $prefix . '%')
                               ->orderBy('work_order_number', 'desc')
                               ->first();

        if (!$lastWorkOrder) {
            return $prefix . '000001';
        }

        $lastNumber = (int) substr($lastWorkOrder->work_order_number, strlen($prefix));
        return $prefix . str_pad($lastNumber + 1, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Assign work order to technician and vehicle
     */
    public function assignTo(?int $technicianId = null, ?int $vehicleId = null, ?int $assignedBy = null): self
    {
        if ($this->status !== 'created') {
            throw new \InvalidArgumentException('Can only assign work orders in created status');
        }

        $updateData = [
            'status' => 'assigned',
            'assigned_at' => now(),
            'assigned_by' => $assignedBy ?? auth()->id(),
        ];

        if ($technicianId) {
            $updateData['assigned_technician_id'] = $technicianId;
        }

        if ($vehicleId) {
            $updateData['assigned_vehicle_id'] = $vehicleId;
        }

        $this->update($updateData);

        return $this->fresh();
    }

    /**
     * Start work order
     */
    public function start(?int $startedBy = null): self
    {
        if (!$this->canBeStarted()) {
            throw new \InvalidArgumentException('Work order cannot be started');
        }

        $this->update([
            'status' => 'in_progress',
            'actual_start' => now(),
        ]);

        // Record technician check-in
        if ($this->assigned_technician_id) {
            TechnicianCheckIn::create([
                'account_id' => $this->account_id,
                'business_id' => $this->business_id,
                'work_order_id' => $this->id,
                'technician_id' => $this->assigned_technician_id,
                'checkin_type' => 'arrival',
                'checkin_time' => now(),
                'status_update' => 'Work started',
            ]);
        }

        return $this->fresh();
    }

    /**
     * Complete work order
     */
    public function complete(array $completionData, ?int $completedBy = null): self
    {
        if (!$this->canBeCompleted()) {
            throw new \InvalidArgumentException('Work order cannot be completed');
        }

        $updateData = [
            'status' => 'completed',
            'actual_end' => now(),
            'completed_by' => $completedBy ?? auth()->id(),
        ];

        // Add completion data if provided
        if (isset($completionData['work_performed'])) {
            $updateData['work_performed'] = $completionData['work_performed'];
        }

        if (isset($completionData['technician_notes'])) {
            $updateData['technician_notes'] = $completionData['technician_notes'];
        }

        if (isset($completionData['completion_signature'])) {
            $updateData['completion_signature'] = $completionData['completion_signature'];
        }

        if (isset($completionData['completion_photos'])) {
            $updateData['completion_photos'] = $completionData['completion_photos'];
        }

        if (isset($completionData['customer_satisfaction'])) {
            $updateData['customer_satisfaction'] = $completionData['customer_satisfaction'];
        }

        if (isset($completionData['actual_cost_minor'])) {
            $updateData['actual_cost_minor'] = $completionData['actual_cost_minor'];
        }

        $this->update($updateData);

        // Record technician departure check-in
        if ($this->assigned_technician_id) {
            TechnicianCheckIn::create([
                'account_id' => $this->account_id,
                'business_id' => $this->business_id,
                'work_order_id' => $this->id,
                'technician_id' => $this->assigned_technician_id,
                'checkin_type' => 'departure',
                'checkin_time' => now(),
                'status_update' => 'Work completed',
            ]);
        }

        return $this->fresh();
    }

    /**
     * Cancel work order
     */
    public function cancel(?string $reason = null, ?int $cancelledBy = null): self
    {
        if ($this->status === 'completed') {
            throw new \InvalidArgumentException('Cannot cancel completed work order');
        }

        $this->update([
            'status' => 'cancelled',
            'technician_notes' => $reason ? 
                ($this->technician_notes ? $this->technician_notes . '; Cancelled: ' . $reason : 'Cancelled: ' . $reason) :
                $this->technician_notes,
        ]);

        return $this->fresh();
    }

    /**
     * Reschedule work order
     */
    public function reschedule(\DateTimeInterface $newStart, \DateTimeInterface $newEnd, ?string $reason = null): self
    {
        if ($this->status === 'completed') {
            throw new \InvalidArgumentException('Cannot reschedule completed work order');
        }

        $this->update([
            'scheduled_start' => $newStart,
            'scheduled_end' => $newEnd,
            'technician_notes' => $reason ?
                ($this->technician_notes ? $this->technician_notes . '; Rescheduled: ' . $reason : 'Rescheduled: ' . $reason) :
                $this->technician_notes,
        ]);

        return $this->fresh();
    }

    /**
     * Add parts used to work order
     */
    public function addPartsUsed(int $productId, float $quantity, int $unitCostMinor, ?string $notes = null): WorkOrderParts
    {
        return WorkOrderParts::create([
            'account_id' => $this->account_id,
            'business_id' => $this->business_id,
            'work_order_id' => $this->id,
            'product_id' => $productId,
            'used_by' => $this->assigned_technician_id ?? auth()->user()->employee->id,
            'quantity_used' => $quantity,
            'unit_cost_minor' => $unitCostMinor,
            'currency' => $this->currency,
            'used_at' => now(),
            'usage_notes' => $notes,
            'source_location' => 'field_inventory',
            'part_condition' => 'new',
        ]);
    }

    /**
     * Get total parts cost
     */
    public function getTotalPartsCost(): Money
    {
        $totalMinor = $this->partsUsed()->get()->sum(function ($part) {
            return $part->quantity_used * $part->unit_cost_minor;
        });

        return new Money((int) $totalMinor, $this->currency);
    }

    /**
     * Calculate estimated completion time based on historical data
     */
    public function getEstimatedCompletionTime(): ?\DateTimeInterface
    {
        if (!$this->actual_start) {
            return null;
        }

        // Use historical average for this type of work
        $avgDuration = static::where('work_order_type', $this->work_order_type)
                            ->whereNotNull('actual_start')
                            ->whereNotNull('actual_end')
                            ->where('status', 'completed')
                            ->get()
                            ->avg(function ($wo) {
                                return $wo->actual_start->diffInMinutes($wo->actual_end);
                            });

        if (!$avgDuration) {
            $avgDuration = $this->estimated_duration_minutes;
        }

        return $this->actual_start->copy()->addMinutes((int) $avgDuration);
    }

    /**
     * Get work order efficiency metrics
     */
    public function getEfficiencyMetrics(): array
    {
        $metrics = [
            'duration_variance' => null,
            'cost_variance' => null,
            'on_time' => null,
            'efficiency_score' => null,
        ];

        // Duration variance
        if ($this->scheduled_start && $this->scheduled_end && $this->actual_start && $this->actual_end) {
            $scheduledDuration = $this->getScheduledDurationMinutes();
            $actualDuration = $this->getActualDurationMinutes();
            
            if ($scheduledDuration > 0) {
                $metrics['duration_variance'] = (($actualDuration - $scheduledDuration) / $scheduledDuration) * 100;
            }
        }

        // Cost variance
        if ($this->estimated_cost_minor > 0 && $this->actual_cost_minor) {
            $metrics['cost_variance'] = (($this->actual_cost_minor - $this->estimated_cost_minor) / $this->estimated_cost_minor) * 100;
        }

        // On-time completion
        if ($this->scheduled_end && $this->actual_end) {
            $metrics['on_time'] = $this->actual_end <= $this->scheduled_end;
        }

        // Overall efficiency score (0-100)
        $score = 100;
        if ($metrics['duration_variance'] !== null) {
            $score -= max(0, $metrics['duration_variance']); // Penalty for taking longer
        }
        if ($metrics['cost_variance'] !== null) {
            $score -= max(0, $metrics['cost_variance']); // Penalty for cost overruns
        }
        if ($metrics['on_time'] === false) {
            $score -= 20; // Penalty for being late
        }

        $metrics['efficiency_score'] = max(0, min(100, $score));

        return $metrics;
    }
}