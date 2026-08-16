<?php

namespace App\Models;

use App\Domain\Tenancy\Concerns\BelongsToAccount;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use App\Domain\Shared\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Daily route planning and optimization
 *
 * Represents a technician's planned route for a day, including
 * work order sequence, distance calculations, and performance tracking.
 */
class DailyRoute extends Model
{
    use HasFactory, BelongsToAccount, BelongsToBusiness, HasPublicId;

    protected $fillable = [
        'account_id',
        'business_id',
        'public_id',
        'route_date',
        'technician_id',
        'vehicle_id',
        'route_name',
        'work_order_sequence',
        'estimated_distance_km',
        'estimated_duration_minutes',
        'planned_start_time',
        'planned_end_time',
        'actual_start_time',
        'actual_end_time',
        'actual_distance_km',
        'fuel_used_liters',
        'route_notes',
        'status',
        'is_optimized',
    ];

    protected $casts = [
        'route_date' => 'date',
        'planned_start_time' => 'datetime',
        'planned_end_time' => 'datetime',
        'actual_start_time' => 'datetime',
        'actual_end_time' => 'datetime',
        'work_order_sequence' => 'array',
        'estimated_distance_km' => 'decimal:2',
        'actual_distance_km' => 'decimal:2',
        'is_optimized' => 'boolean',
    ];

    public const STATUSES = [
        'planned' => 'Planned',
        'in_progress' => 'In Progress',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ];

    // ── Relationships ──────────────────────────────────────────────────────

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'technician_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(FleetVehicle::class, 'vehicle_id');
    }

    // ── Accessors ──────────────────────────────────────────────────────────

    /**
     * Get work orders in route sequence
     */
    public function getWorkOrders(): array
    {
        if (empty($this->work_order_sequence)) {
            return [];
        }

        return WorkOrder::whereIn('id', $this->work_order_sequence)
                       ->get()
                       ->sortBy(function ($workOrder) {
                           return array_search($workOrder->id, $this->work_order_sequence);
                       })
                       ->values()
                       ->toArray();
    }

    /**
     * Get planned duration in minutes
     */
    public function getPlannedDurationMinutes(): ?int
    {
        if (!$this->planned_start_time || !$this->planned_end_time) {
            return null;
        }

        return $this->planned_start_time->diffInMinutes($this->planned_end_time);
    }

    /**
     * Get actual duration in minutes
     */
    public function getActualDurationMinutes(): ?int
    {
        if (!$this->actual_start_time || !$this->actual_end_time) {
            return null;
        }

        return $this->actual_start_time->diffInMinutes($this->actual_end_time);
    }

    /**
     * Get fuel efficiency for this route
     */
    public function getFuelEfficiency(): ?float
    {
        if (!$this->actual_distance_km || !$this->fuel_used_liters || $this->fuel_used_liters == 0) {
            return null;
        }

        return $this->actual_distance_km / $this->fuel_used_liters;
    }

    // ── Query Scopes ───────────────────────────────────────────────────────

    public function scopeStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeForDate($query, \DateTimeInterface $date)
    {
        return $query->where('route_date', $date->format('Y-m-d'));
    }

    public function scopeForTechnician($query, int $technicianId)
    {
        return $query->where('technician_id', $technicianId);
    }

    public function scopeOptimized($query)
    {
        return $query->where('is_optimized', true);
    }

    // ── Business Logic ─────────────────────────────────────────────────────

    /**
     * Start the route
     */
    public function start(): self
    {
        if ($this->status !== 'planned') {
            throw new \InvalidArgumentException('Can only start planned routes');
        }

        $this->update([
            'status' => 'in_progress',
            'actual_start_time' => now(),
        ]);

        return $this->fresh();
    }

    /**
     * Complete the route
     */
    public function complete(array $completionData = []): self
    {
        if ($this->status !== 'in_progress') {
            throw new \InvalidArgumentException('Can only complete in-progress routes');
        }

        $updateData = [
            'status' => 'completed',
            'actual_end_time' => now(),
        ];

        if (isset($completionData['actual_distance_km'])) {
            $updateData['actual_distance_km'] = $completionData['actual_distance_km'];
        }

        if (isset($completionData['fuel_used_liters'])) {
            $updateData['fuel_used_liters'] = $completionData['fuel_used_liters'];
        }

        if (isset($completionData['route_notes'])) {
            $updateData['route_notes'] = $completionData['route_notes'];
        }

        $this->update($updateData);

        return $this->fresh();
    }

    /**
     * Add work order to route
     */
    public function addWorkOrder(int $workOrderId, ?int $position = null): bool
    {
        if ($this->status !== 'planned') {
            return false;
        }

        $sequence = $this->work_order_sequence ?? [];
        
        if (in_array($workOrderId, $sequence)) {
            return false; // Already in route
        }

        if ($position === null || $position >= count($sequence)) {
            $sequence[] = $workOrderId;
        } else {
            array_splice($sequence, $position, 0, $workOrderId);
        }

        return $this->update(['work_order_sequence' => $sequence]);
    }

    /**
     * Remove work order from route
     */
    public function removeWorkOrder(int $workOrderId): bool
    {
        if ($this->status !== 'planned') {
            return false;
        }

        $sequence = $this->work_order_sequence ?? [];
        $key = array_search($workOrderId, $sequence);
        
        if ($key === false) {
            return false; // Not in route
        }

        unset($sequence[$key]);
        $sequence = array_values($sequence); // Re-index

        return $this->update(['work_order_sequence' => $sequence]);
    }

    /**
     * Optimize route order (simple implementation)
     */
    public function optimizeRoute(): bool
    {
        if ($this->status !== 'planned' || empty($this->work_order_sequence)) {
            return false;
        }

        // Get work orders with their locations
        $workOrders = WorkOrder::whereIn('id', $this->work_order_sequence)
                              ->get()
                              ->keyBy('id');

        // Simple optimization: sort by priority then by scheduled time
        $optimized = collect($this->work_order_sequence)
                        ->sortBy(function ($workOrderId) use ($workOrders) {
                            $wo = $workOrders[$workOrderId] ?? null;
                            if (!$wo) return 0;
                            
                            // Priority weight (higher priority first)
                            $priorityWeight = $wo->getPriorityWeight() * 1000;
                            
                            // Time preference (earlier times first)
                            $timeWeight = $wo->scheduled_start ? 
                                $wo->scheduled_start->hour * 60 + $wo->scheduled_start->minute : 0;
                            
                            return -$priorityWeight + $timeWeight;
                        })
                        ->values()
                        ->toArray();

        return $this->update([
            'work_order_sequence' => $optimized,
            'is_optimized' => true,
        ]);
    }

    /**
     * Get route performance metrics
     */
    public function getPerformanceMetrics(): array
    {
        $metrics = [
            'completion_rate' => 0,
            'on_time_rate' => 0,
            'efficiency_score' => 0,
            'fuel_efficiency' => $this->getFuelEfficiency(),
            'duration_variance' => null,
            'distance_variance' => null,
        ];

        if ($this->status === 'completed') {
            $workOrders = $this->getWorkOrders();
            $totalOrders = count($workOrders);
            
            if ($totalOrders > 0) {
                $completedOrders = collect($workOrders)->where('status', 'completed')->count();
                $metrics['completion_rate'] = ($completedOrders / $totalOrders) * 100;
                
                // Calculate on-time rate (simplified)
                $onTimeOrders = collect($workOrders)
                    ->filter(fn($wo) => $wo['status'] === 'completed' && 
                             $wo['actual_end'] && $wo['scheduled_end'] &&
                             $wo['actual_end'] <= $wo['scheduled_end'])
                    ->count();
                
                $metrics['on_time_rate'] = ($onTimeOrders / $totalOrders) * 100;
            }

            // Duration variance
            if ($this->getPlannedDurationMinutes() && $this->getActualDurationMinutes()) {
                $planned = $this->getPlannedDurationMinutes();
                $actual = $this->getActualDurationMinutes();
                $metrics['duration_variance'] = (($actual - $planned) / $planned) * 100;
            }

            // Distance variance
            if ($this->estimated_distance_km && $this->actual_distance_km) {
                $metrics['distance_variance'] = 
                    (($this->actual_distance_km - $this->estimated_distance_km) / $this->estimated_distance_km) * 100;
            }

            // Overall efficiency score
            $score = 100;
            $score = min($score, $metrics['completion_rate']);
            $score = ($score + $metrics['on_time_rate']) / 2;
            
            if ($metrics['duration_variance'] !== null) {
                $score -= max(0, $metrics['duration_variance'] / 2); // Penalty for taking longer
            }
            
            $metrics['efficiency_score'] = max(0, min(100, $score));
        }

        return $metrics;
    }
}