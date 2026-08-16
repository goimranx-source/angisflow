<?php

namespace App\Domain\FieldService;

use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Tenancy\TenantContext;
use App\Models\FleetVehicle;
use App\Models\VehicleAssignment;
use App\Models\Employee;
use App\Models\DailyRoute;
use App\Models\FieldInventory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Service for managing fleet vehicles and assignments
 *
 * Handles vehicle lifecycle, technician assignments, maintenance tracking,
 * and fleet performance analytics.
 */
class FleetManagementService
{
    /**
     * Create a new fleet vehicle
     */
    public function createVehicle(array $data): FleetVehicle
    {
        $tenant = app(TenantContext::class);
        
        $vehicleData = array_merge($data, [
            'account_id' => $tenant->account()->id,
            'business_id' => $tenant->business()->id,
            'vehicle_number' => $data['vehicle_number'] ?? FleetVehicle::generateVehicleNumber(),
            'currency' => $tenant->business()->base_currency,
        ]);

        return FleetVehicle::create($vehicleData);
    }

    /**
     * Update vehicle information
     */
    public function updateVehicle(FleetVehicle $vehicle, array $data): FleetVehicle
    {
        $vehicle->update($data);
        return $vehicle->fresh();
    }

    /**
     * Assign vehicle to employee
     */
    public function assignVehicle(FleetVehicle $vehicle, Employee $employee, string $assignmentType = 'primary', ?string $notes = null): VehicleAssignment
    {
        return $vehicle->assignTo($employee, $assignmentType, $notes);
    }

    /**
     * Unassign vehicle from current employee
     */
    public function unassignVehicle(FleetVehicle $vehicle, ?string $notes = null): bool
    {
        return $vehicle->unassign($notes);
    }

    /**
     * Update vehicle odometer
     */
    public function updateOdometer(FleetVehicle $vehicle, int $newReading, ?string $notes = null): bool
    {
        return $vehicle->updateOdometer($newReading, $notes);
    }

    /**
     * Update vehicle GPS location
     */
    public function updateVehicleLocation(FleetVehicle $vehicle, float $latitude, float $longitude): bool
    {
        return $vehicle->updateLocation($latitude, $longitude);
    }

    /**
     * Record vehicle service
     */
    public function recordService(FleetVehicle $vehicle, ?int $odometerReading = null, ?string $notes = null): bool
    {
        return $vehicle->recordService($odometerReading, $notes);
    }

    /**
     * Get available vehicles
     */
    public function getAvailableVehicles(?string $vehicleType = null): Collection
    {
        $tenant = app(TenantContext::class);
        
        $query = FleetVehicle::where('business_id', $tenant->business()->id)
                            ->available()
                            ->with(['activeAssignment.employee']);

        if ($vehicleType) {
            $query->type($vehicleType);
        }

        return $query->orderBy('vehicle_number')->get();
    }

    /**
     * Get vehicles needing service
     */
    public function getVehiclesNeedingService(): Collection
    {
        $tenant = app(TenantContext::class);
        
        return FleetVehicle::where('business_id', $tenant->business()->id)
                          ->active()
                          ->needsService()
                          ->with(['activeAssignment.employee'])
                          ->orderBy('current_odometer_km', 'desc')
                          ->get();
    }

    /**
     * Get vehicles with expiring documents
     */
    public function getVehiclesWithExpiringDocuments(int $days = 30): Collection
    {
        $tenant = app(TenantContext::class);
        
        return FleetVehicle::where('business_id', $tenant->business()->id)
                          ->active()
                          ->expiringDocuments($days)
                          ->orderBy('insurance_expiry')
                          ->orderBy('registration_expiry')
                          ->get();
    }

    /**
     * Get fleet utilization report
     */
    public function getFleetUtilization(\DateTimeInterface $start, \DateTimeInterface $end): array
    {
        $tenant = app(TenantContext::class);
        
        $vehicles = FleetVehicle::where('business_id', $tenant->business()->id)
                               ->active()
                               ->with(['routes' => function ($q) use ($start, $end) {
                                   $q->whereBetween('route_date', [$start->format('Y-m-d'), $end->format('Y-m-d')])
                                     ->where('status', 'completed');
                               }])
                               ->get();

        $utilizationData = [];
        $totalDays = $start->diffInDays($end) + 1;

        foreach ($vehicles as $vehicle) {
            $workingDays = $vehicle->routes->unique('route_date')->count();
            $utilizationRate = $totalDays > 0 ? ($workingDays / $totalDays) * 100 : 0;
            
            $totalDistance = $vehicle->routes->sum('actual_distance_km');
            $totalFuel = $vehicle->routes->sum('fuel_used_liters');
            $fuelEfficiency = $totalFuel > 0 ? $totalDistance / $totalFuel : null;

            $utilizationData[] = [
                'vehicle_id' => $vehicle->id,
                'vehicle_number' => $vehicle->vehicle_number,
                'vehicle_display' => $vehicle->getDisplayName(),
                'utilization_rate' => round($utilizationRate, 1),
                'working_days' => $workingDays,
                'total_distance_km' => round($totalDistance, 2),
                'fuel_efficiency_kmpl' => $fuelEfficiency ? round($fuelEfficiency, 2) : null,
                'current_assignment' => $vehicle->getCurrentAssignment()?->employee->name,
            ];
        }

        // Sort by utilization rate descending
        usort($utilizationData, fn($a, $b) => $b['utilization_rate'] <=> $a['utilization_rate']);

        return [
            'period' => [
                'from' => $start->format('Y-m-d'),
                'to' => $end->format('Y-m-d'),
            ],
            'vehicles' => $utilizationData,
            'summary' => [
                'total_vehicles' => count($utilizationData),
                'average_utilization' => count($utilizationData) > 0 
                    ? round(array_sum(array_column($utilizationData, 'utilization_rate')) / count($utilizationData), 1)
                    : 0,
                'total_distance' => round(array_sum(array_column($utilizationData, 'total_distance_km')), 2),
                'vehicles_needing_service' => $this->getVehiclesNeedingService()->count(),
            ],
        ];
    }

    /**
     * Get vehicle maintenance alerts
     */
    public function getMaintenanceAlerts(): array
    {
        $tenant = app(TenantContext::class);
        
        $vehicles = FleetVehicle::where('business_id', $tenant->business()->id)
                               ->active()
                               ->get();

        $alerts = [];
        foreach ($vehicles as $vehicle) {
            $vehicleAlerts = $vehicle->getMaintenanceAlerts();
            foreach ($vehicleAlerts as $alert) {
                $alerts[] = array_merge($alert, [
                    'vehicle_id' => $vehicle->id,
                    'vehicle_number' => $vehicle->vehicle_number,
                    'vehicle_display' => $vehicle->getDisplayName(),
                ]);
            }
        }

        // Sort by severity (high first) then by vehicle
        usort($alerts, function ($a, $b) {
            $severityOrder = ['high' => 3, 'medium' => 2, 'low' => 1];
            $severityDiff = ($severityOrder[$b['severity']] ?? 0) - ($severityOrder[$a['severity']] ?? 0);
            
            if ($severityDiff !== 0) {
                return $severityDiff;
            }
            
            return strcmp($a['vehicle_number'], $b['vehicle_number']);
        });

        return $alerts;
    }

    /**
     * Create daily route for technician
     */
    public function createDailyRoute(\DateTimeInterface $date, int $technicianId, ?int $vehicleId = null, array $workOrderIds = []): DailyRoute
    {
        $tenant = app(TenantContext::class);
        
        // Ensure no existing route for this technician on this date
        $existingRoute = DailyRoute::where('business_id', $tenant->business()->id)
                                  ->where('route_date', $date->format('Y-m-d'))
                                  ->where('technician_id', $technicianId)
                                  ->first();

        if ($existingRoute) {
            throw new \InvalidArgumentException('Route already exists for this technician on this date');
        }

        // If no vehicle specified, try to get technician's assigned vehicle
        if (!$vehicleId) {
            $assignment = VehicleAssignment::where('employee_id', $technicianId)
                                          ->where('is_active', true)
                                          ->first();
            $vehicleId = $assignment?->vehicle_id;
        }

        return DailyRoute::create([
            'account_id' => $tenant->account()->id,
            'business_id' => $tenant->business()->id,
            'public_id' => \Str::ulid(),
            'route_date' => $date->format('Y-m-d'),
            'technician_id' => $technicianId,
            'vehicle_id' => $vehicleId,
            'work_order_sequence' => $workOrderIds,
            'planned_start_time' => $date->copy()->setTime(8, 0),
            'planned_end_time' => $date->copy()->setTime(17, 0),
            'status' => 'planned',
        ]);
    }

    /**
     * Optimize all routes for a specific date
     */
    public function optimizeRoutesForDate(\DateTimeInterface $date): array
    {
        $tenant = app(TenantContext::class);
        
        $routes = DailyRoute::where('business_id', $tenant->business()->id)
                           ->forDate($date)
                           ->where('status', 'planned')
                           ->get();

        $optimizedRoutes = [];
        foreach ($routes as $route) {
            if ($route->optimizeRoute()) {
                $optimizedRoutes[] = [
                    'route_id' => $route->id,
                    'technician_id' => $route->technician_id,
                    'work_orders_count' => count($route->work_order_sequence ?? []),
                ];
            }
        }

        return $optimizedRoutes;
    }

    /**
     * Get field inventory status
     */
    public function getFieldInventoryStatus(?int $technicianId = null): array
    {
        $tenant = app(TenantContext::class);
        
        $query = FieldInventory::where('business_id', $tenant->business()->id)
                              ->with(['technician', 'vehicle', 'product']);

        if ($technicianId) {
            $query->where('technician_id', $technicianId);
        }

        $inventory = $query->get();

        $lowStockItems = $inventory->filter->needsReorder();
        
        $summary = [
            'total_items' => $inventory->count(),
            'low_stock_items' => $lowStockItems->count(),
            'total_value' => 0, // Would need product costs to calculate
            'technicians_with_inventory' => $inventory->unique('technician_id')->count(),
        ];

        $itemsByTechnician = $inventory->groupBy('technician_id')->map(function ($items, $technicianId) {
            $technician = $items->first()->technician;
            return [
                'technician_id' => $technicianId,
                'technician_name' => $technician->name,
                'vehicle_id' => $items->first()->vehicle_id,
                'total_items' => $items->count(),
                'low_stock_items' => $items->filter->needsReorder()->count(),
                'items' => $items->map(function ($item) {
                    return [
                        'product_id' => $item->product_id,
                        'product_name' => $item->product->name,
                        'quantity_on_hand' => $item->quantity_on_hand,
                        'available_quantity' => $item->getAvailableQuantity(),
                        'reorder_level' => $item->reorder_level,
                        'needs_reorder' => $item->needsReorder(),
                    ];
                })->values()->toArray(),
            ];
        })->values()->toArray();

        return [
            'summary' => $summary,
            'low_stock_alert' => $lowStockItems->map(function ($item) {
                return [
                    'technician_name' => $item->technician->name,
                    'product_name' => $item->product->name,
                    'available_quantity' => $item->getAvailableQuantity(),
                    'reorder_level' => $item->reorder_level,
                ];
            })->values()->toArray(),
            'technicians' => $itemsByTechnician,
        ];
    }

    /**
     * Restock field inventory
     */
    public function restockFieldInventory(int $technicianId, int $productId, float $quantity, ?string $notes = null): bool
    {
        $tenant = app(TenantContext::class);
        
        $inventory = FieldInventory::where('business_id', $tenant->business()->id)
                                  ->where('technician_id', $technicianId)
                                  ->where('product_id', $productId)
                                  ->first();

        if (!$inventory) {
            // Create new inventory record
            $technician = Employee::find($technicianId);
            $vehicleAssignment = $technician->vehicleAssignments()
                                           ->where('is_active', true)
                                           ->first();

            $inventory = FieldInventory::create([
                'account_id' => $tenant->account()->id,
                'business_id' => $tenant->business()->id,
                'technician_id' => $technicianId,
                'vehicle_id' => $vehicleAssignment?->vehicle_id,
                'product_id' => $productId,
                'quantity_on_hand' => $quantity,
                'last_restocked' => now()->toDateString(),
                'location_notes' => $notes,
            ]);

            return true;
        }

        // Update existing inventory
        return $inventory->update([
            'quantity_on_hand' => $inventory->quantity_on_hand + $quantity,
            'last_restocked' => now()->toDateString(),
            'location_notes' => $notes ?: $inventory->location_notes,
        ]);
    }

    /**
     * Transfer inventory between technicians
     */
    public function transferInventory(int $fromTechnicianId, int $toTechnicianId, int $productId, float $quantity): bool
    {
        return DB::transaction(function () use ($fromTechnicianId, $toTechnicianId, $productId, $quantity) {
            $tenant = app(TenantContext::class);
            
            $fromInventory = FieldInventory::where('business_id', $tenant->business()->id)
                                          ->where('technician_id', $fromTechnicianId)
                                          ->where('product_id', $productId)
                                          ->first();

            if (!$fromInventory || $fromInventory->getAvailableQuantity() < $quantity) {
                return false;
            }

            // Reduce from source
            $fromInventory->decrement('quantity_on_hand', $quantity);

            // Add to destination (or create if doesn't exist)
            $toTechnician = Employee::find($toTechnicianId);
            $vehicleAssignment = $toTechnician->vehicleAssignments()
                                             ->where('is_active', true)
                                             ->first();

            $toInventory = FieldInventory::where('business_id', $tenant->business()->id)
                                        ->where('technician_id', $toTechnicianId)
                                        ->where('product_id', $productId)
                                        ->first();

            if ($toInventory) {
                $toInventory->increment('quantity_on_hand', $quantity);
            } else {
                FieldInventory::create([
                    'account_id' => $tenant->account()->id,
                    'business_id' => $tenant->business()->id,
                    'technician_id' => $toTechnicianId,
                    'vehicle_id' => $vehicleAssignment?->vehicle_id,
                    'product_id' => $productId,
                    'quantity_on_hand' => $quantity,
                    'last_restocked' => now()->toDateString(),
                ]);
            }

            return true;
        });
    }

    /**
     * Get vehicle cost analysis
     */
    public function getVehicleCostAnalysis(\DateTimeInterface $start, \DateTimeInterface $end): array
    {
        $tenant = app(TenantContext::class);
        
        $vehicles = FleetVehicle::where('business_id', $tenant->business()->id)
                               ->active()
                               ->with(['routes' => function ($q) use ($start, $end) {
                                   $q->whereBetween('route_date', [$start->format('Y-m-d'), $end->format('Y-m-d')])
                                     ->where('status', 'completed');
                               }])
                               ->get();

        $analysis = [];
        foreach ($vehicles as $vehicle) {
            $totalDistance = $vehicle->routes->sum('actual_distance_km');
            $totalFuel = $vehicle->routes->sum('fuel_used_liters');
            
            // Estimate fuel cost (would need fuel price data)
            $estimatedFuelCost = $totalFuel * 1.5; // Placeholder cost per liter
            
            // Calculate depreciation (simplified)
            $dailyDepreciation = $vehicle->purchase_price_minor / (365 * 5); // 5-year depreciation
            $periodDays = $start->diffInDays($end) + 1;
            $depreciationCost = $dailyDepreciation * $periodDays;

            $analysis[] = [
                'vehicle_id' => $vehicle->id,
                'vehicle_number' => $vehicle->vehicle_number,
                'vehicle_display' => $vehicle->getDisplayName(),
                'total_distance_km' => round($totalDistance, 2),
                'fuel_used_liters' => round($totalFuel, 2),
                'estimated_fuel_cost' => round($estimatedFuelCost, 2),
                'depreciation_cost' => round($depreciationCost / 100, 2), // Convert to major currency units
                'cost_per_km' => $totalDistance > 0 ? round(($estimatedFuelCost + $depreciationCost / 100) / $totalDistance, 3) : 0,
                'utilization_rate' => $vehicle->getUtilizationRate($periodDays),
            ];
        }

        return [
            'period' => [
                'from' => $start->format('Y-m-d'),
                'to' => $end->format('Y-m-d'),
            ],
            'vehicles' => $analysis,
            'totals' => [
                'total_distance' => round(array_sum(array_column($analysis, 'total_distance_km')), 2),
                'total_fuel_cost' => round(array_sum(array_column($analysis, 'estimated_fuel_cost')), 2),
                'total_depreciation' => round(array_sum(array_column($analysis, 'depreciation_cost')), 2),
            ],
        ];
    }
}