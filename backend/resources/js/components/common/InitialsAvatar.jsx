import { cn } from '@/lib/utils';

function getInitials(name) {
    if (!name) {
        return '?';
    }

    return name
        .trim()
        .split(/\s+/)
        .slice(0, 2)
        .map((part) => part[0])
        .join('')
        .toUpperCase();
}

export default function InitialsAvatar({ name, size = 'md', className }) {
    return (
        <span
            aria-hidden="true"
            className={cn(
                'inline-flex shrink-0 items-center justify-center rounded-full bg-indigo-100 font-semibold text-indigo-700 ring-1 ring-indigo-200/70',
                size === 'sm' && 'size-9 text-xs',
                size === 'md' && 'size-10 text-sm',
                size === 'lg' && 'size-12 text-base',
                className,
            )}
        >
            {getInitials(name)}
        </span>
    );
}

export { getInitials };
