<?php

namespace App\Models;

use App\Domain\Tenancy\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

/**
 * A holiday within a holiday calendar
 *
 * Represents a specific holiday or non-working day with its date, observance
 * rules, and business impact. Supports both fixed dates and calculated holidays
 * (Easter, Thanksgiving, etc.) with recurrence patterns.
 */
class Holiday extends Model
{
    use HasFactory, BelongsToAccount;

    protected $fillable = [
        'account_id',
        'holiday_calendar_id',
        'name',
        'date',
        'year',
        'recurrence_type',
        'recurrence_rules',
        'is_business_day',
        'is_half_day',
        'observance_type',
        'observed_date',
        'holiday_type',
        'description',
        'is_regional',
    ];

    protected $casts = [
        'date' => 'date',
        'observed_date' => 'date',
        'recurrence_rules' => 'array',
        'is_business_day' => 'boolean',
        'is_half_day' => 'boolean',
        'is_regional' => 'boolean',
        'year' => 'integer',
    ];

    public const RECURRENCE_TYPES = [
        'none' => 'No Recurrence',
        'annual' => 'Annual (Same Date)',
        'custom' => 'Custom Calculation',
    ];

    public const OBSERVANCE_TYPES = [
        'exact' => 'Exact Date',
        'nearest_weekday' => 'Nearest Weekday',
        'monday_shift' => 'Monday if Weekend',
    ];

    public const HOLIDAY_TYPES = [
        'national' => 'National Holiday',
        'religious' => 'Religious Holiday',
        'cultural' => 'Cultural Holiday',
        'bank' => 'Bank Holiday',
        'industry' => 'Industry-Specific',
    ];

    public function holidayCalendar(): BelongsTo
    {
        return $this->belongsTo(HolidayCalendar::class);
    }

    /**
     * Get the effective date (observed date if different from actual)
     */
    public function getEffectiveDate(): Carbon
    {
        return $this->observed_date ?? $this->date;
    }

    /**
     * Check if this holiday falls on a weekend
     */
    public function isOnWeekend(): bool
    {
        return $this->date->isWeekend();
    }

    /**
     * Check if the holiday is observed on a different date
     */
    public function isObservedOnDifferentDate(): bool
    {
        return $this->observed_date !== null && 
               $this->observed_date->toDateString() !== $this->date->toDateString();
    }

    /**
     * Get the duration of the holiday
     */
    public function getDuration(): string
    {
        if ($this->is_half_day) {
            return 'Half Day';
        }
        
        return 'Full Day';
    }

    /**
     * Check if this holiday affects business operations
     */
    public function affectsBusinessOperations(): bool
    {
        return !$this->is_business_day;
    }

    /**
     * Get formatted holiday description
     */
    public function getFormattedDescription(): string
    {
        $parts = [];
        
        if ($this->holiday_type) {
            $parts[] = $this->holidayTypeLabel();
        }
        
        if ($this->is_regional) {
            $parts[] = 'Regional';
        }
        
        if ($this->is_half_day) {
            $parts[] = 'Half Day';
        }
        
        if (!$this->is_business_day) {
            $parts[] = 'Non-Working Day';
        }
        
        $prefix = $parts ? '(' . implode(', ', $parts) . ')' : '';
        
        return trim($this->description . ' ' . $prefix);
    }

    /**
     * Create next year's instance of this holiday
     */
    public function createNextYearInstance(): ?self
    {
        if ($this->recurrence_type === 'none') {
            return null;
        }
        
        $nextYear = $this->year + 1;
        
        // Check if next year's holiday already exists
        if (self::where('holiday_calendar_id', $this->holiday_calendar_id)
               ->where('name', $this->name)
               ->where('year', $nextYear)
               ->exists()) {
            return null;
        }
        
        $nextDate = $this->calculateNextYearDate($nextYear);
        if (!$nextDate) {
            return null;
        }
        
        return self::create([
            'account_id' => $this->account_id,
            'holiday_calendar_id' => $this->holiday_calendar_id,
            'name' => $this->name,
            'date' => $nextDate->toDateString(),
            'year' => $nextYear,
            'recurrence_type' => $this->recurrence_type,
            'recurrence_rules' => $this->recurrence_rules,
            'is_business_day' => $this->is_business_day,
            'is_half_day' => $this->is_half_day,
            'observance_type' => $this->observance_type,
            'holiday_type' => $this->holiday_type,
            'description' => $this->description,
            'is_regional' => $this->is_regional,
        ]);
    }

    /**
     * Calculate the date for next year based on recurrence rules
     */
    private function calculateNextYearDate(int $nextYear): ?Carbon
    {
        if ($this->recurrence_type === 'annual') {
            return Carbon::parse($this->date)->setYear($nextYear);
        }
        
        if ($this->recurrence_type === 'custom' && $this->recurrence_rules) {
            return $this->holidayCalendar->calculateCustomHoliday($this->recurrence_rules, $nextYear);
        }
        
        return null;
    }

    public function recurrenceTypeLabel(): string
    {
        return self::RECURRENCE_TYPES[$this->recurrence_type] ?? ucfirst($this->recurrence_type);
    }

    public function observanceTypeLabel(): string
    {
        return self::OBSERVANCE_TYPES[$this->observance_type] ?? ucfirst($this->observance_type);
    }

    public function holidayTypeLabel(): string
    {
        return self::HOLIDAY_TYPES[$this->holiday_type] ?? ucfirst($this->holiday_type ?? '');
    }

    /**
     * Scope to get holidays for a specific date range
     */
    public function scopeInDateRange($query, Carbon $startDate, Carbon $endDate)
    {
        return $query->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
                     ->orWhereBetween('observed_date', [$startDate->toDateString(), $endDate->toDateString()]);
    }

    /**
     * Scope to get non-business days only
     */
    public function scopeNonBusinessDays($query)
    {
        return $query->where('is_business_day', false);
    }

    /**
     * Scope to get holidays for a specific year
     */
    public function scopeForYear($query, int $year)
    {
        return $query->where('year', $year);
    }

    /**
     * Create common holidays for a calendar
     */
    public static function createCommonUSHolidays(HolidayCalendar $calendar, int $year): void
    {
        $holidays = [
            [
                'name' => "New Year's Day",
                'date' => Carbon::create($year, 1, 1),
                'holiday_type' => 'national',
                'observance_type' => 'nearest_weekday',
            ],
            [
                'name' => 'Martin Luther King Jr. Day',
                'date' => Carbon::create($year, 1, 1)->nthOfMonth(3, Carbon::MONDAY),
                'holiday_type' => 'national',
                'recurrence_type' => 'custom',
                'recurrence_rules' => [
                    'type' => 'nth_weekday',
                    'month' => 1,
                    'weekday' => Carbon::MONDAY,
                    'occurrence' => 3,
                ],
            ],
            [
                'name' => 'Independence Day',
                'date' => Carbon::create($year, 7, 4),
                'holiday_type' => 'national',
                'observance_type' => 'nearest_weekday',
            ],
            [
                'name' => 'Labor Day',
                'date' => Carbon::create($year, 9, 1)->firstOfMonth(Carbon::MONDAY),
                'holiday_type' => 'national',
                'recurrence_type' => 'custom',
                'recurrence_rules' => [
                    'type' => 'nth_weekday',
                    'month' => 9,
                    'weekday' => Carbon::MONDAY,
                    'occurrence' => 1,
                ],
            ],
            [
                'name' => 'Thanksgiving',
                'date' => Carbon::create($year, 11, 1)->nthOfMonth(4, Carbon::THURSDAY),
                'holiday_type' => 'national',
                'recurrence_type' => 'custom',
                'recurrence_rules' => [
                    'type' => 'nth_weekday',
                    'month' => 11,
                    'weekday' => Carbon::THURSDAY,
                    'occurrence' => 4,
                ],
            ],
            [
                'name' => 'Christmas Day',
                'date' => Carbon::create($year, 12, 25),
                'holiday_type' => 'national',
                'observance_type' => 'nearest_weekday',
            ],
        ];

        foreach ($holidays as $holidayData) {
            self::create([
                'account_id' => $calendar->account_id,
                'holiday_calendar_id' => $calendar->id,
                'name' => $holidayData['name'],
                'date' => $holidayData['date']->toDateString(),
                'year' => $year,
                'recurrence_type' => $holidayData['recurrence_type'] ?? 'annual',
                'recurrence_rules' => $holidayData['recurrence_rules'] ?? null,
                'is_business_day' => false,
                'observance_type' => $holidayData['observance_type'] ?? 'exact',
                'holiday_type' => $holidayData['holiday_type'],
            ]);
        }
    }
}