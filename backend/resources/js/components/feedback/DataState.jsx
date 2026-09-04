import { AlertCircle, Inbox, LoaderCircle } from 'lucide-react';

import { cn } from '@/lib/utils';

const stateMap = {
    empty: {
        icon: Inbox,
        iconClass: 'bg-muted text-muted-foreground',
    },
    loading: {
        icon: LoaderCircle,
        iconClass: 'bg-primary/10 text-primary',
    },
    error: {
        icon: AlertCircle,
        iconClass: 'bg-rose-100 text-rose-600 dark:bg-rose-950/40 dark:text-rose-300',
    },
};

export default function DataState({ variant = 'empty', title, description, compact = false }) {
    const state = stateMap[variant] ?? stateMap.empty;
    const Icon = state.icon;

    return (
        <div className={cn('flex flex-col items-center justify-center text-center', compact ? 'px-4 py-8' : 'min-h-64 px-6 py-12')} role={variant === 'error' ? 'alert' : variant === 'loading' ? 'status' : undefined}>
            <span className={cn('mb-3 flex size-11 items-center justify-center rounded-2xl', state.iconClass)}>
                <Icon className={cn('size-5', variant === 'loading' && 'animate-spin motion-reduce:animate-none')} />
            </span>
            <p className="text-sm font-semibold text-foreground">{title}</p>
            {description && <p className="mt-1 max-w-sm text-sm leading-6 text-muted-foreground">{description}</p>}
        </div>
    );
}
