<?php

namespace App\Models;

use App\Domain\Tenancy\Concerns\BelongsToAccount;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;

/**
 * Advanced permission policy for conditional access control
 *
 * Enables sophisticated access control rules based on attributes,
 * time, location, or other contextual factors. Complements the
 * role-based system with policy-based access control (PBAC).
 */
class PermissionPolicy extends Model
{
    use HasFactory, BelongsToAccount, BelongsToBusiness, HasPublicId, SoftDeletes;

    protected $fillable = [
        'account_id',
        'business_id',
        'public_id',
        'policy_name',
        'policy_type',
        'description',
        'rules',
        'conditions',
        'effect',
        'priority',
        'is_active',
        'effective_from',
        'effective_until',
        'created_by',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'rules' => 'array',
        'conditions' => 'array',
        'is_active' => 'boolean',
        'effective_from' => 'date',
        'effective_until' => 'date',
        'approved_at' => 'datetime',
    ];

    public const POLICY_TYPES = [
        'role_based' => 'Role-Based Policy',
        'attribute_based' => 'Attribute-Based Policy',
        'time_based' => 'Time-Based Policy',
        'location_based' => 'Location-Based Policy',
        'device_based' => 'Device-Based Policy',
        'risk_based' => 'Risk-Based Policy',
        'workflow_based' => 'Workflow-Based Policy',
    ];

    public const EFFECTS = [
        'allow' => 'Allow Access',
        'deny' => 'Deny Access',
        'require_approval' => 'Require Approval',
        'require_mfa' => 'Require Multi-Factor Authentication',
        'limit_access' => 'Limited Access',
    ];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Check if policy is currently effective
     */
    public function isEffective(?Carbon $at = null): bool
    {
        $at = $at ?? now();
        
        if (!$this->is_active) {
            return false;
        }

        if ($this->effective_from && $at->isBefore($this->effective_from)) {
            return false;
        }

        if ($this->effective_until && $at->isAfter($this->effective_until)) {
            return false;
        }

        return true;
    }

    /**
     * Evaluate policy against context
     */
    public function evaluate(array $context): bool
    {
        if (!$this->isEffective()) {
            return false;
        }

        // Check conditions first
        if (!$this->conditionsMatch($context)) {
            return false;
        }

        // Evaluate rules
        return $this->rulesMatch($context);
    }

    /**
     * Check if conditions match the context
     */
    protected function conditionsMatch(array $context): bool
    {
        if (empty($this->conditions)) {
            return true; // No conditions means always applies
        }

        foreach ($this->conditions as $condition) {
            if (!$this->evaluateCondition($condition, $context)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if rules match the context
     */
    protected function rulesMatch(array $context): bool
    {
        if (empty($this->rules)) {
            return true; // No rules means always matches
        }

        foreach ($this->rules as $rule) {
            if (!$this->evaluateRule($rule, $context)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Evaluate a single condition
     */
    protected function evaluateCondition(array $condition, array $context): bool
    {
        $field = $condition['field'] ?? null;
        $operator = $condition['operator'] ?? 'equals';
        $value = $condition['value'] ?? null;
        $contextValue = $context[$field] ?? null;

        return match ($operator) {
            'equals' => $contextValue == $value,
            'not_equals' => $contextValue != $value,
            'contains' => is_string($contextValue) && str_contains($contextValue, $value),
            'not_contains' => is_string($contextValue) && !str_contains($contextValue, $value),
            'in' => is_array($value) && in_array($contextValue, $value, true),
            'not_in' => is_array($value) && !in_array($contextValue, $value, true),
            'greater_than' => is_numeric($contextValue) && is_numeric($value) && $contextValue > $value,
            'less_than' => is_numeric($contextValue) && is_numeric($value) && $contextValue < $value,
            'regex' => is_string($contextValue) && preg_match($value, $contextValue),
            'time_between' => $this->evaluateTimeBetween($contextValue, $value),
            'date_between' => $this->evaluateDateBetween($contextValue, $value),
            default => false,
        };
    }

    /**
     * Evaluate a single rule
     */
    protected function evaluateRule(array $rule, array $context): bool
    {
        $type = $rule['type'] ?? 'condition';

        return match ($type) {
            'condition' => $this->evaluateCondition($rule, $context),
            'role_required' => $this->evaluateRoleRequired($rule, $context),
            'capability_required' => $this->evaluateCapabilityRequired($rule, $context),
            'business_hours' => $this->evaluateBusinessHours($rule, $context),
            'location_allowed' => $this->evaluateLocationAllowed($rule, $context),
            'device_trusted' => $this->evaluateDeviceTrusted($rule, $context),
            default => false,
        };
    }

    /**
     * Evaluate time between condition
     */
    protected function evaluateTimeBetween($contextValue, array $timeRange): bool
    {
        if (!isset($timeRange['start']) || !isset($timeRange['end'])) {
            return false;
        }

        $current = Carbon::parse($contextValue ?? now());
        $start = Carbon::parse($timeRange['start']);
        $end = Carbon::parse($timeRange['end']);

        return $current->between($start, $end);
    }

    /**
     * Evaluate date between condition
     */
    protected function evaluateDateBetween($contextValue, array $dateRange): bool
    {
        if (!isset($dateRange['start']) || !isset($dateRange['end'])) {
            return false;
        }

        $current = Carbon::parse($contextValue ?? now())->startOfDay();
        $start = Carbon::parse($dateRange['start'])->startOfDay();
        $end = Carbon::parse($dateRange['end'])->endOfDay();

        return $current->between($start, $end);
    }

    /**
     * Evaluate role requirement
     */
    protected function evaluateRoleRequired(array $rule, array $context): bool
    {
        $requiredRoles = $rule['roles'] ?? [];
        $userRoles = $context['user_roles'] ?? [];

        if (empty($requiredRoles)) {
            return true;
        }

        return !empty(array_intersect($requiredRoles, $userRoles));
    }

    /**
     * Evaluate capability requirement
     */
    protected function evaluateCapabilityRequired(array $rule, array $context): bool
    {
        $requiredCapabilities = $rule['capabilities'] ?? [];
        $userCapabilities = $context['user_capabilities'] ?? [];

        if (empty($requiredCapabilities)) {
            return true;
        }

        return !empty(array_intersect($requiredCapabilities, $userCapabilities));
    }

    /**
     * Evaluate business hours restriction
     */
    protected function evaluateBusinessHours(array $rule, array $context): bool
    {
        $businessHours = $rule['hours'] ?? [];
        $current = Carbon::now();

        if (empty($businessHours)) {
            return true;
        }

        $dayOfWeek = strtolower($current->format('l'));
        $dayHours = $businessHours[$dayOfWeek] ?? null;

        if (!$dayHours || !isset($dayHours['start']) || !isset($dayHours['end'])) {
            return false; // No hours defined for this day
        }

        $start = Carbon::parse($dayHours['start']);
        $end = Carbon::parse($dayHours['end']);

        return $current->format('H:i') >= $start->format('H:i') && 
               $current->format('H:i') <= $end->format('H:i');
    }

    /**
     * Evaluate location allowed
     */
    protected function evaluateLocationAllowed(array $rule, array $context): bool
    {
        $allowedLocations = $rule['locations'] ?? [];
        $userLocation = $context['location'] ?? null;

        if (empty($allowedLocations) || !$userLocation) {
            return true;
        }

        return in_array($userLocation, $allowedLocations, true);
    }

    /**
     * Evaluate device trusted
     */
    protected function evaluateDeviceTrusted(array $rule, array $context): bool
    {
        $requireTrustedDevice = $rule['require_trusted'] ?? false;
        $deviceTrusted = $context['device_trusted'] ?? false;

        return !$requireTrustedDevice || $deviceTrusted;
    }

    /**
     * Get policy effect on access
     */
    public function getAccessEffect(array $context): ?string
    {
        if (!$this->evaluate($context)) {
            return null;
        }

        return $this->effect;
    }

    public function getPolicyTypeLabel(): string
    {
        return self::POLICY_TYPES[$this->policy_type] ?? ucfirst(str_replace('_', ' ', $this->policy_type));
    }

    public function getEffectLabel(): string
    {
        return self::EFFECTS[$this->effect] ?? ucfirst(str_replace('_', ' ', $this->effect));
    }

    /**
     * Scope for active policies
     */
    public function scopeActive($query, ?Carbon $at = null)
    {
        $at = $at ?? now();
        
        return $query->where('is_active', true)
                    ->where(function ($q) use ($at) {
                        $q->whereNull('effective_from')
                          ->orWhere('effective_from', '<=', $at->toDateString());
                    })
                    ->where(function ($q) use ($at) {
                        $q->whereNull('effective_until')
                          ->orWhere('effective_until', '>=', $at->toDateString());
                    });
    }

    /**
     * Scope by priority order
     */
    public function scopeByPriority($query)
    {
        return $query->orderBy('priority', 'desc');
    }

    /**
     * Create default business hours policy
     */
    public static function createBusinessHoursPolicy(int $businessId, array $businessHours): self
    {
        return self::create([
            'account_id' => auth()->user()->account_id,
            'business_id' => $businessId,
            'policy_name' => 'Business Hours Access',
            'policy_type' => 'time_based',
            'description' => 'Restricts access to business hours only',
            'rules' => [
                [
                    'type' => 'business_hours',
                    'hours' => $businessHours,
                ]
            ],
            'conditions' => [],
            'effect' => 'allow',
            'priority' => 100,
            'is_active' => true,
            'created_by' => auth()->id(),
        ]);
    }

    /**
     * Create emergency access policy
     */
    public static function createEmergencyAccessPolicy(int $businessId): self
    {
        return self::create([
            'account_id' => auth()->user()->account_id,
            'business_id' => $businessId,
            'policy_name' => 'Emergency Access Override',
            'policy_type' => 'risk_based',
            'description' => 'Allows emergency access with enhanced logging',
            'rules' => [
                [
                    'type' => 'condition',
                    'field' => 'is_emergency_access',
                    'operator' => 'equals',
                    'value' => true,
                ]
            ],
            'conditions' => [],
            'effect' => 'require_approval',
            'priority' => 200,
            'is_active' => true,
            'created_by' => auth()->id(),
        ]);
    }
}