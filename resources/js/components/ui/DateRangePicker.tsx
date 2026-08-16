import { useState } from 'react';

import { Icon } from '@/components/ui/Icon';
import { cn } from '@/lib/utils';

type DateRange = {
    start: Date;
    end: Date;
};

type DateRangePickerProps = {
    value?: DateRange;
    onChange?: (range: DateRange) => void;
    className?: string;
};

/**
 * Date range picker with calendar dropdown.
 * 
 * Professional date picker matching DreamsERP style.
 */
export function DateRangePicker({ value, onChange: _onChange, className }: DateRangePickerProps) {
    const [isOpen, setIsOpen] = useState(false);
    // No calendar UI yet (see below), so the range never actually changes.
    const [selectedRange] = useState<DateRange | null>(value || null);

    const formatDateRange = (range: DateRange | null) => {
        if (!range) return 'Select date range';
        const start = range.start.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: '2-digit' });
        const end = range.end.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: '2-digit' });
        return `${start} to ${end}`;
    };

    return (
        <div className={cn('relative', className)}>
            <button
                type="button"
                onClick={() => setIsOpen(!isOpen)}
                className="flex items-center gap-2 rounded-lg border border-[var(--color-border-light)] bg-white px-3 py-2 text-sm font-medium text-[var(--color-text-main)] transition-colors hover:bg-[var(--color-surface)]"
            >
                <Icon name="calendar" size={16} />
                <span>{formatDateRange(selectedRange)}</span>
                <Icon name="caret-down" size={14} />
            </button>

            {isOpen && (
                <>
                    <div
                        className="fixed inset-0 z-40"
                        onClick={() => setIsOpen(false)}
                        aria-hidden
                    />
                    <div className="absolute right-0 top-full z-50 mt-2 rounded-lg border border-[var(--color-border-light)] bg-white p-4 shadow-lg">
                        <p className="text-sm text-[var(--color-text-muted)]">
                            Calendar implementation pending
                        </p>
                    </div>
                </>
            )}
        </div>
    );
}
