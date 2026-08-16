<?php

namespace App\Models;

use App\Domain\Tenancy\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Carbon\Carbon;

/**
 * Holiday calendar for a localization pack
 *
 * Defines holidays and non-working days for business operations, scheduling,
 * and payroll calculations. Supports multiple calendar types (national,
 * religious, industry-specific) with configurable observance rules.
 */
class HolidayCalendar extends Model
{
    use HasFactory, BelongsToAccount;

    protected $fillable = [
        'account_id',
        'localization_pack_id',
        'calendar_name',
        'calendar_type',
        'description',
        'is_default_calendar',
        'affects_business_days',
        'observance_rules',
    ];

    protected $casts = [
        'is_default_calendar' => 'boolean',
        'affects_business_days' => 'boolean',
        'observance_rules' => 'array',
    ];

    public const CALENDAR_TYPES = [
        'national' => 'National Holidays',
        'religious' => 'Religious Holidays',
        'industry' => 'Industry-Specific',
        'custom' => 'Custom Calendar',
    ];

    public function localizationPack(): BelongsTo
    {
        return $this->belongsTo(LocalizationPack::class);
    }

    public function holidays(): HasMany
    {
        return $this->hasMany(Holiday::class);
    }

    /**
     * Get holidays for a specific year
     */
    public function getHolidaysForYear(int $year): \Illuminate\Database\Eloquent\Collection
    {
        return $this->holidays()
            ->where('year', $year)
            ->orderBy('date')
            ->get();
    }

    /**
     * Check if a date is a holiday
     */
    public function isHoliday(Carbon|string $date): bool
    {
        if (is_string($date)) {
            $date = Carbon::parse($date);
        }
        
        return $this->holidays()
            ->where('year', $date->year)
            ->where(function ($query) use ($date) {
                $query->where('date', $date->toDateString())
                      ->orWhere('observed_date', $date->toDateString());
            })
            ->exists();
    }

    /**
     * Check if a date is a business day (not a holiday)
     */
    public function isBusinessDay(Carbon|string $date): bool
    {
        if (!$this->affects_business_days) {
            return true; // This calendar doesn't affect business days
        }
        
        if (is_string($date)) {
            $date = Carbon::parse($date);
        }
        
        $holiday = $this->holidays()
            ->where('year', $date->year)
            ->where(function ($query) use ($date) {
                $query->where('date', $date->toDateString())
                      ->orWhere('observed_date', $date->toDateString());
            })
            ->first();
        
        return $holiday ? $holiday->is_business_day : true;
    }

    /**
     * Get next business day after a given date
     */
    public function getNextBusinessDay(Carbon|string $date): Carbon
    {
        if (is_string($date)) {
            $date = Carbon::parse($date);
        }
        
        $nextDay = $date->copy()->addDay();
        
        // Skip weekends and holidays
        while ($nextDay->isWeekend() || !$this->isBusinessDay($nextDay)) {
            $nextDay->addDay();
        }
        
        return $nextDay;
    }

    /**
     * Get previous business day before a given date
     */
    public function getPreviousBusinessDay(Carbon|string $date): Carbon
    {
        if (is_string($date)) {
            $date = Carbon::parse($date);
        }
        
        $prevDay = $date->copy()->subDay();
        
        // Skip weekends and holidays
        while ($prevDay->isWeekend() || !$this->isBusinessDay($prevDay)) {
            $prevDay->subDay();
        }
        
        return $prevDay;
    }

    /**
     * Count business days between two dates
     */
    public function countBusinessDaysBetween(Carbon|string $startDate, Carbon|string $endDate): int
    {
        if (is_string($startDate)) {
            $startDate = Carbon::parse($startDate);
        }
        if (is_string($endDate)) {
            $endDate = Carbon::parse($endDate);
        }
        
        $count = 0;
        $current = $startDate->copy()->addDay();
        
        while ($current->lessThanOrEqualTo($endDate)) {
            if (!$current->isWeekend() && $this->isBusinessDay($current)) {
                $count++;
            }
            $current->addDay();
        }
        
        return $count;
    }

    /**
     * Generate holidays for a year based on recurrence rules
     */
    public function generateHolidaysForYear(int $year): array
    {
        $holidays = [];
        
        // Get template holidays that have recurrence rules
        $templateHolidays = $this->holidays()
            ->where('recurrence_type', '!=', 'none')
            ->get();
        
        foreach ($templateHolidays as $template) {
            if ($template->recurrence_type === 'annual') {
                // Simple annual recurrence - same day each year
                $holidayDate = Carbon::parse($template->date)->setYear($year);
                
                $holidays[] = [
                    'name' => $template->name,
                    'date' => $holidayDate->toDateString(),
                    'year' => $year,
                    'recurrence_type' => $template->recurrence_type,
                    'is_business_day' => $template->is_business_day,
                    'is_half_day' => $template->is_half_day,
                    'observance_type' => $template->observance_type,
                    'holiday_type' => $template->holiday_type,
                    'description' => $template->description,
                    'is_regional' => $template->is_regional,
                ];
            } elseif ($template->recurrence_type === 'custom' && $template->recurrence_rules) {
                // Custom recurrence rules (Easter, Thanksgiving, etc.)
                $calculatedDate = $this->calculateCustomHoliday($template->recurrence_rules, $year);
                if ($calculatedDate) {
                    $holidays[] = [
                        'name' => $template->name,
                        'date' => $calculatedDate->toDateString(),
                        'year' => $year,
                        'recurrence_type' => $template->recurrence_type,
                        'recurrence_rules' => $template->recurrence_rules,
                        'is_business_day' => $template->is_business_day,
                        'is_half_day' => $template->is_half_day,
                        'observance_type' => $template->observance_type,
                        'holiday_type' => $template->holiday_type,
                        'description' => $template->description,
                        'is_regional' => $template->is_regional,
                    ];
                }
            }
        }
        
        // Apply observance rules (weekend shifts, etc.)
        return array_map([$this, 'applyObservanceRules'], $holidays);
    }

    /**
     * Calculate custom holiday dates (Easter, Thanksgiving, etc.)
     */
    private function calculateCustomHoliday(array $rules, int $year): ?Carbon
    {
        if (!isset($rules['type'])) {
            return null;
        }
        
        return match ($rules['type']) {
            'easter' => $this->calculateEaster($year)->addDays($rules['offset'] ?? 0),
            'nth_weekday' => $this->calculateNthWeekday($rules, $year),
            'last_weekday' => $this->calculateLastWeekday($rules, $year),
            default => null,
        };
    }

    /**
     * Calculate Easter date for a given year
     */
    private function calculateEaster(int $year): Carbon
    {
        // Algorithm for calculating Easter date
        $a = $year % 19;
        $b = intval($year / 100);
        $c = $year % 100;
        $d = intval($b / 4);
        $e = $b % 4;
        $f = intval(($b + 8) / 25);
        $g = intval(($b - $f + 1) / 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intval($c / 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intval(($a + 11 * $h + 22 * $l) / 451);
        $month = intval(($h + $l - 7 * $m + 114) / 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;
        
        return Carbon::create($year, $month, $day);
    }

    /**
     * Calculate nth weekday of month (e.g., 3rd Monday of February)
     */
    private function calculateNthWeekday(array $rules, int $year): ?Carbon
    {
        if (!isset($rules['month'], $rules['weekday'], $rules['occurrence'])) {
            return null;
        }
        
        $firstDay = Carbon::create($year, $rules['month'], 1);
        $firstWeekday = $firstDay->copy()->firstOfMonth($rules['weekday']);
        
        return $firstWeekday->addWeeks($rules['occurrence'] - 1);
    }

    /**
     * Calculate last weekday of month (e.g., last Monday of May)
     */
    private function calculateLastWeekday(array $rules, int $year): ?Carbon
    {
        if (!isset($rules['month'], $rules['weekday'])) {
            return null;
        }
        
        $lastDay = Carbon::create($year, $rules['month'], 1)->endOfMonth();
        
        return $lastDay->lastOfMonth($rules['weekday']);
    }

    /**
     * Apply observance rules (weekend shifts, etc.)
     */
    private function applyObservanceRules(array $holiday): array
    {
        $date = Carbon::parse($holiday['date']);
        $observedDate = $date->copy();
        
        if ($holiday['observance_type'] === 'nearest_weekday' && $date->isWeekend()) {
            if ($date->isSaturday()) {
                $observedDate->subDay(); // Friday
            } else {
                $observedDate->addDay(); // Monday
            }
        } elseif ($holiday['observance_type'] === 'monday_shift' && $date->isWeekend()) {
            $observedDate->next(Carbon::MONDAY);
        }
        
        $holiday['observed_date'] = $observedDate->toDateString();
        
        return $holiday;
    }

    public function calendarTypeLabel(): string
    {
        return self::CALENDAR_TYPES[$this->calendar_type] ?? ucfirst($this->calendar_type);
    }
}