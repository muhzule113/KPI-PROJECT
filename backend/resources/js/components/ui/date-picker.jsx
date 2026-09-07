import * as React from 'react';
import { CalendarDays, ChevronDown, ChevronLeft, ChevronRight } from 'lucide-react';
import { Popover } from 'radix-ui';

import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';

const weekdays = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];
const monthFormatter = new Intl.DateTimeFormat('id-ID', { month: 'long', year: 'numeric' });
const dateFormatter = new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'long', year: 'numeric' });
const accessibleDateFormatter = new Intl.DateTimeFormat('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });

const parseDate = (value) => {
    if (!value) return null;
    const [year, month, day] = value.split('-').map(Number);
    const date = new Date(year, month - 1, day);
    return date.getFullYear() === year && date.getMonth() === month - 1 && date.getDate() === day ? date : null;
};

export const formatDateValue = (date = new Date()) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
const startOfMonth = (date) => new Date(date.getFullYear(), date.getMonth(), 1);
const sameDay = (left, right) => Boolean(left && right && formatDateValue(left) === formatDateValue(right));

const calendarDays = (month) => {
    const firstDay = startOfMonth(month);
    const start = new Date(firstDay);
    start.setDate(firstDay.getDate() - firstDay.getDay());

    return Array.from({ length: 42 }, (_, index) => {
        const date = new Date(start);
        date.setDate(start.getDate() + index);
        return date;
    });
};

export function DatePicker({
    id,
    value = '',
    onChange,
    min,
    max,
    disabled = false,
    clearable = false,
    clearLabel = 'Kosongkan',
    placeholder = 'Pilih tanggal',
    className,
    'aria-label': ariaLabel,
    'aria-describedby': ariaDescribedBy,
}) {
    const selected = parseDate(value);
    const minDate = parseDate(min);
    const maxDate = parseDate(max);
    const today = new Date();
    const initialMonth = selected ?? (maxDate && today > maxDate ? maxDate : minDate && today < minDate ? minDate : today);
    const [open, setOpen] = React.useState(false);
    const [visibleMonth, setVisibleMonth] = React.useState(() => startOfMonth(initialMonth));
    const changeOpen = (nextOpen) => {
        if (nextOpen) setVisibleMonth(startOfMonth(selected ?? initialMonth));
        setOpen(nextOpen);
    };

    const days = calendarDays(visibleMonth);
    const previousMonth = new Date(visibleMonth.getFullYear(), visibleMonth.getMonth() - 1, 1);
    const nextMonth = new Date(visibleMonth.getFullYear(), visibleMonth.getMonth() + 1, 1);
    const canGoPrevious = !minDate || new Date(previousMonth.getFullYear(), previousMonth.getMonth() + 1, 0) >= minDate;
    const canGoNext = !maxDate || nextMonth <= startOfMonth(maxDate);
    const isDisabled = (date) => (minDate && date < minDate) || (maxDate && date > maxDate);
    const choose = (date) => {
        onChange(formatDateValue(date));
        setOpen(false);
    };

    return (
        <Popover.Root open={open} onOpenChange={changeOpen}>
            <Popover.Trigger asChild>
                <Button
                    id={id}
                    type="button"
                    variant="outline"
                    disabled={disabled}
                    aria-label={ariaLabel}
                    aria-describedby={ariaDescribedBy}
                    className={cn('min-h-11 w-full justify-between gap-3 bg-background px-3 font-medium sm:w-auto', className)}
                >
                    <span className="flex min-w-0 items-center gap-2">
                        <CalendarDays className="size-4 text-primary" aria-hidden="true" />
                        <span className={cn('truncate', !selected && 'text-muted-foreground')}>
                            {selected ? dateFormatter.format(selected) : placeholder}
                        </span>
                    </span>
                    <ChevronDown className="size-4 text-muted-foreground" aria-hidden="true" />
                </Button>
            </Popover.Trigger>
            <Popover.Portal>
                <Popover.Content
                    align="end"
                    sideOffset={8}
                    collisionPadding={8}
                    role="dialog"
                    aria-label="Pilih tanggal"
                    className="z-50 w-[min(23rem,calc(100vw-1rem))] rounded-2xl border border-border bg-popover p-3 text-popover-foreground shadow-lg outline-none"
                >
                    <div className="flex items-center justify-between gap-3">
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="size-11"
                            disabled={!canGoPrevious}
                            aria-label="Bulan sebelumnya"
                            onClick={() => setVisibleMonth(previousMonth)}
                        >
                            <ChevronLeft aria-hidden="true" />
                        </Button>
                        <p className="font-semibold capitalize" aria-live="polite">{monthFormatter.format(visibleMonth)}</p>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="size-11"
                            disabled={!canGoNext}
                            aria-label="Bulan berikutnya"
                            onClick={() => setVisibleMonth(nextMonth)}
                        >
                            <ChevronRight aria-hidden="true" />
                        </Button>
                    </div>

                    <div className="mt-2 grid grid-cols-7 text-center text-xs font-medium text-muted-foreground" aria-hidden="true">
                        {weekdays.map((weekday) => <span key={weekday} className="py-2">{weekday}</span>)}
                    </div>
                    <div className="grid grid-cols-7">
                        {days.map((date) => {
                            const outsideMonth = date.getMonth() !== visibleMonth.getMonth();
                            const selectedDay = sameDay(date, selected);
                            const todayDate = sameDay(date, today);
                            const unavailable = isDisabled(date);

                            return (
                                <button
                                    key={formatDateValue(date)}
                                    type="button"
                                    data-date={formatDateValue(date)}
                                    disabled={unavailable}
                                    aria-label={`Pilih ${accessibleDateFormatter.format(date)}`}
                                    aria-pressed={selectedDay}
                                    onClick={() => choose(date)}
                                    className={cn(
                                        'min-h-11 min-w-0 rounded-lg text-sm outline-none transition-colors hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring disabled:pointer-events-none disabled:opacity-30',
                                        outsideMonth && 'text-muted-foreground/60',
                                        todayDate && !selectedDay && 'border border-primary/60 font-semibold text-primary',
                                        selectedDay && 'bg-primary font-semibold text-primary-foreground hover:bg-primary/90',
                                    )}
                                >
                                    {date.getDate()}
                                </button>
                            );
                        })}
                    </div>

                    <div className="mt-3 flex items-center justify-between gap-2 border-t border-border pt-3">
                        {clearable ? (
                            <Button type="button" variant="ghost" className="min-h-11" onClick={() => { onChange(''); setOpen(false); }}>
                                {clearLabel}
                            </Button>
                        ) : <span />}
                        <Button type="button" variant="outline" className="min-h-11" disabled={isDisabled(today)} onClick={() => choose(today)}>
                            Hari ini
                        </Button>
                    </div>
                </Popover.Content>
            </Popover.Portal>
        </Popover.Root>
    );
}
