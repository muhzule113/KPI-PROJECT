import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import { formatNumber, formatPercent } from '@/utils/formatters';

const toneMap = {
    primary: 'bg-primary/10 text-primary',
    success: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300',
    warning: 'bg-amber-100 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300',
    danger: 'bg-rose-100 text-rose-700 dark:bg-rose-950/40 dark:text-rose-300',
};

export default function MetricCard({ label, value, description, icon: Icon, tone = 'primary', percentage = false }) {
    const displayValue = value === null || value === undefined
        ? 'Belum ada'
        : percentage
            ? formatPercent(value)
            : formatNumber(value, 0);

    return (
        <Card className="border-border/80 shadow-sm shadow-slate-950/[0.025]">
            <CardContent className="flex items-start justify-between gap-4 p-5 sm:p-6">
                <div className="min-w-0">
                    <p className="text-sm font-medium text-muted-foreground">{label}</p>
                    <p className="mt-3 truncate text-2xl font-semibold tracking-tight text-foreground sm:text-3xl">{displayValue}</p>
                    <p className="mt-2 line-clamp-2 text-xs leading-5 text-muted-foreground">{description}</p>
                </div>
                <span className={cn('flex size-11 shrink-0 items-center justify-center rounded-2xl', toneMap[tone] ?? toneMap.primary)}>
                    <Icon className="size-5" strokeWidth={1.9} />
                </span>
            </CardContent>
        </Card>
    );
}
