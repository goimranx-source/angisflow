<?php

namespace App\Models;

use App\Domain\Tenancy\Concerns\BelongsToAccount;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Shared\ValueObjects\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A fleet vehicle used for field service operations
 *
 * Represents company vehicles that can be assigned to technicians
 * for field service work, with maintenance and tracking capabilities.
 */
class FleetVehicle extends Model
{
    use HasFactory, BelongsToAccount, BelongsToBusiness, HasPublicId, SoftDeletes;

    protected $fillable = [
        'account_id',
        'business_id',
        'public_id',
        'vehicle_number',
        'license_plate',
        'vin',
        'make',
        'model',
        'year',
        'color',
        'vehicle_type',
        'ownership_type',
        'status',
        'purchase_date',
        'purchase_price_minor',
        'currency',
        'lease_start_date',
        'lease_end_date',
        'engine_type',
        'engine_capacity',
        'max_payload_kg',
        'seating_capacity',
        'fuel_capacity_liters',
        'current_odometer_km',
        'last_service_date',
        'service_interval_km',
        'insurance_expiry',
        'registration_expiry',
        'notes',
        'last_latitude',
        'last_longitude',
        'last_location_update',
        'gps_enabled',
    ];

    protected $casts = [
        'purchase_date' => 'date',
        'lease_start_date' => 'date',
        'lease_end_date' => 'date',
        'last_service_date' => 'date',
        'insurance_expiry' => 'date',
        'registration_expiry' => 'date',
        'last_location_update' => 'datetime',
        'gps_enabled' => 'boolean',
        'engine_capacity' => 'decimal:2',
        'fuel_capacity_liters' => 'decimal:2',
        'last_latitude' => 'decimal:7',
        'last_longitude' => 'decimal:7',
    ];

    public const STATUSES = [
        'active' => 'Active',
        'maintenance' => 'In Maintenance',
        'retired' => 'Retired',
        'sold' => 'Sold',
    ];

    public const OWNERSHIP_TYPES = [
        'owned' => 'Owned',
        'leased' => 'Leased',
        'rental' => 'Rental',
    ];

    public const VEHICLE_TYPES = [
        'van' => 'Van',
        'truck' => 'Truck',
        'car' => 'Car',
        'motorcycle' => 'Motorcycle',
        'trailer' => 'Trailer',
    ];

    public const ENGINE_TYPES = [
        'diesel' => 'Diesel',
        'petrol' => 'Petrol/Gasoline',
        'electric' => 'Electric',
        'hybrid' => 'Hybrid',
        'cng' => 'Compressed Natural Gas',
    ];

    // ── Relationships ──────────────────────────────────────────────────────

    public function assignments(): HasMany
    {
        return $this->hasMany(VehicleAssignment::class, 'vehicle_id');
    }

    public function activeAssignment(): HasMany
    {
        return $this->hasMany(VehicleAssignment::class, 'vehicle_id')
                    ->where('is_active', true);
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class, 'assigned_vehicle_id');
    }

    public function fieldInventory(): HasMany
    {
        return $this->hasMany(FieldInventory::class, 'vehicle_id');
    }

    public function routes(): HasMany
    {
        return $this->hasMany(DailyRoute::class, 'vehicle_id');
    }

    // ── Accessors ──────────────────────────────────────────────────────────

    /**
     * Get the purchase price as a Money object
     */
    public function getPurchasePrice(): Money
    {
        return new Money($this->purchase_price_minor, $this->currency);
    }

    /**
     * Get the vehicle display name
     */
    public function getDisplayName(): string
    {
        return "{$this->year} {$this->make} {$this->model} ({$this->license_plate})";
    }

    /**
     * Get currently assigned employee
     */
    public function getCurrentAssignment(): ?VehicleAssignment
    {
        return $this->activeAssignment()->with('employee')->first();
    }

    /**
     * Check if vehicle is available for assignment
     */
    public function isAvailable(): bool
    {
        return $this->status === 'active' && $this->getCurrentAssignment() === null;
    }

    /**
     * Check if vehicle needs service
     */
    public function needsService(): bool
    {
        if (!$this->last_service_date || !$this->service_interval_km) {
            return false;
        }

        $kmSinceService = $this->current_odometer_km - $this->getKmAtLastService();
        return $kmSinceService >= $this->service_interval_km;
    }

    /**
     * Check if insurance is expiring soon (within 30 days)
     */
    public function hasExpiringInsurance(): bool
    {
        if (!$this->insurance_expiry) {
            return false;
        }

        return $this->insurance_expiry <= now()->addDays(30);
    }

    /**
     * Check if registration is expiring soon (within 30 days)
     */
    public function hasExpiringRegistration(): bool
    {
        if (!$this->registration_expiry) {
            return false;
        }

        return $this->registration_expiry <= now()->addDays(30);
    }

    /**
     * Get fuel efficiency (km per liter)
     */
    public function getFuelEfficiency(?int $days = 30): ?float
    {
        $routes = $this->routes()
                      ->where('route_date', '>=', now()->subDays($days))
                      ->whereNotNull('actual_distance_km')
                      ->whereNotNull('fuel_used_liters')
                      ->get();

        if ($routes->isEmpty()) {
            return null;
        }

        $totalDistance = $routes->sum('actual_distance_km');
        $totalFuel = $routes->sum('fuel_used_liters');

        return $totalFuel > 0 ? $totalDistance / $totalFuel : null;
    }

    /**
     * Get vehicle utilization rate
     */
    public function getUtilizationRate(?int $days = 30): float
    {
        $totalDays = $days;
        $workingDays = $this->routes()
                           ->where('route_date', '>=', now()->subDays($days))
                           ->where('status', 'completed')
                           ->distinct('route_date')
                           ->count();

        return $totalDays > 0 ? ($workingDays / $totalDays) * 100 : 0;
    }

    // ── Query Scopes ───────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeAvailable($query)
    {
        return $query->where('status', 'active')
                     ->whereDoesntHave('activeAssignment');
    }

    public function scopeType($query, string $vehicleType)
    {
        return $query->where('vehicle_type', $vehicleType);
    }

    public function scopeNeedsService($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('last_service_date')
              ->orWhereRaw('current_odometer_km - COALESCE((SELECT current_odometer_km FROM fleet_vehicles WHERE id = fleet_vehicles.id AND last_service_date IS NOT NULL), 0) >= service_interval_km');
        });
    }

    public function scopeExpiringDocuments($query, int $days = 30)
    {
        return $query->where(function ($q) use ($days) {
            $q->where('insurance_expiry', '<=', now()->addDays($days))
              ->orWhere('registration_expiry', '<=', now()->addDays($days));
        });
    }

    // ── Business Logic ─────────────────────────────────────────────────────

    /**
     * Generate unique vehicle number
     */
    public static function generateVehicleNumber(string $prefix = 'VH'): string
    {
        $lastVehicle = static::where('vehicle_number', 'like', $prefix . '-%')
                            ->orderBy('vehicle_number', 'desc')
                            ->first();

        if (!$lastVehicle) {
            return $prefix . '-001';
        }

        $lastNumber = (int) substr($lastVehicle->vehicle_number, strlen($prefix) + 1);
        return $prefix . '-' . str_pad($lastNumber + 1, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Assign vehicle to employee
     */
    public function assignTo(Employee $employee, string $assignmentType = 'primary', ?string $notes = null): VehicleAssignment
    {
        // End any existing active assignments
        $this->assignments()->where('is_active', true)->update([
            'is_active' => false,
            'unassigned_date' => now()->toDateString(),
        ]);

        return VehicleAssignment::create([
            'account_id' => $this->account_id,
            'business_id' => $this->business_id,
            'vehicle_id' => $this->id,
            'employee_id' => $employee->id,
            'assigned_date' => now()->toDateString(),
            'assignment_type' => $assignmentType,
            'assignment_notes' => $notes,
            'is_active' => true,
        ]);
    }

    /**
     * Unassign vehicle from current employee
     */
    public function unassign(?string $notes = null): bool
    {
        $activeAssignment = $this->getCurrentAssignment();
        
        if (!$activeAssignment) {
            return false;
        }

        return $activeAssignment->update([
            'is_active' => false,
            'unassigned_date' => now()->toDateString(),
            'assignment_notes' => $notes ? 
                ($activeAssignment->assignment_notes ? $activeAssignment->assignment_notes . '; ' . $notes : $notes) :
                $activeAssignment->assignment_notes,
        ]);
    }

    /**
     * Update odometer reading
     */
    public function updateOdometer(int $newReading, ?string $notes = null): bool
    {
        if ($newReading < $this->current_odometer_km) {
            throw new \InvalidArgumentException('New odometer reading cannot be less than current reading');
        }

        return $this->update([
            'current_odometer_km' => $newReading,
            'notes' => $notes ? 
                ($this->notes ? $this->notes . '; ' . $notes : $notes) :
                $this->notes,
        ]);
    }

    /**
     * Update GPS location
     */
    public function updateLocation(float $latitude, float $longitude): bool
    {
        return $this->update([
            'last_latitude' => $latitude,
            'last_longitude' => $longitude,
            'last_location_update' => now(),
        ]);
    }

    /**
     * Record service performed
     */
    public function recordService(?int $odometerReading = null, ?string $notes = null): bool
    {
        $updateData = [
            'last_service_date' => now()->toDateString(),
        ];

        if ($odometerReading) {
            $updateData['current_odometer_km'] = $odometerReading;
        }

        if ($notes) {
            $updateData['notes'] = $this->notes ? $this->notes . '; Service: ' . $notes : 'Service: ' . $notes;
        }

        return $this->update($updateData);
    }

    /**
     * Get maintenance alerts
     */
    public function getMaintenanceAlerts(): array
    {
        $alerts = [];

        if ($this->needsService()) {
            $kmOverdue = $this->current_odometer_km - $this->getKmAtLastService() - $this->service_interval_km;
            $alerts[] = [
                'type' => 'service_due',
                'severity' => $kmOverdue > 1000 ? 'high' : 'medium',
                'message' => "Service due (overdue by {$kmOverdue}km)",
                'due_km' => $this->getKmAtLastService() + $this->service_interval_km,
            ];
        }

        if ($this->hasExpiringInsurance()) {
            $daysLeft = now()->diffInDays($this->insurance_expiry, false);
            $alerts[] = [
                'type' => 'insurance_expiry',
                'severity' => $daysLeft <= 7 ? 'high' : 'medium',
                'message' => "Insurance expires in {$daysLeft} days",
                'expiry_date' => $this->insurance_expiry,
            ];
        }

        if ($this->hasExpiringRegistration()) {
            $daysLeft = now()->diffInDays($this->registration_expiry, false);
            $alerts[] = [
                'type' => 'registration_expiry',
                'severity' => $daysLeft <= 7 ? 'high' : 'medium',
                'message' => "Registration expires in {$daysLeft} days",
                'expiry_date' => $this->registration_expiry,
            ];
        }

        return $alerts;
    }

    // ── Private Helper Methods ─────────────────────────────────────────────

    private function getKmAtLastService(): int
    {
        // In a real implementation, you might track this in a separate table
        // For now, we'll estimate based on service interval
        return max(0, $this->current_odometer_km - $this->service_interval_km);
    }
}