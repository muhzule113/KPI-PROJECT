import { createContext, useContext, useEffect, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { AlertCircle, CheckCircle2, Info, MessageCircleQuestion, X } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

const FeedbackContext = createContext(null);

const toastStyles = {
    success: {
        icon: CheckCircle2,
        iconClass: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-300',
        title: 'Berhasil',
    },
    error: {
        icon: AlertCircle,
        iconClass: 'bg-destructive/10 text-destructive',
        title: 'Perlu perhatian',
    },
    info: {
        icon: Info,
        iconClass: 'bg-primary/10 text-primary',
        title: 'Informasi',
    },
};

function Toast({ toast, onClose }) {
    const style = toastStyles[toast.tone] ?? toastStyles.info;
    const Icon = style.icon;

    return (
        <div
            className="app-popup-enter pointer-events-auto w-full max-w-md rounded-2xl border border-border/80 bg-card/95 p-3 shadow-2xl shadow-slate-950/15 backdrop-blur-xl"
            role={toast.tone === 'error' ? 'alert' : 'status'}
            aria-live={toast.tone === 'error' ? 'assertive' : 'polite'}
        >
            <div className="flex items-start gap-3">
                <span className={cn('mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-xl', style.iconClass)}>
                    <Icon className="size-[18px]" />
                </span>
                <div className="min-w-0 flex-1 pt-0.5">
                    <p className="text-xs font-semibold uppercase tracking-[0.12em] text-muted-foreground">{style.title}</p>
                    <p className="mt-1 text-sm font-medium leading-5 text-card-foreground">{toast.message}</p>
                </div>
                <button
                    type="button"
                    onClick={onClose}
                    className="rounded-lg p-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    aria-label="Tutup notifikasi"
                >
                    <X className="size-4" />
                </button>
            </div>
        </div>
    );
}

function ActionDialog({ request, onClose }) {
    const prompt = typeof request.prompt === 'string'
        ? { label: request.prompt, name: 'value' }
        : request.prompt;
    const [value, setValue] = useState(prompt?.defaultValue ?? '');
    const [error, setError] = useState('');

    useEffect(() => {
        const handleKeyDown = (event) => {
            if (event.key === 'Escape') {
                onClose({ confirmed: false });
            }
        };

        window.addEventListener('keydown', handleKeyDown);
        return () => window.removeEventListener('keydown', handleKeyDown);
    }, [onClose]);

    const submit = (event) => {
        event.preventDefault();

        if (prompt?.required && !value.trim()) {
            setError(prompt.label + ' wajib diisi.');
            return;
        }

        if (prompt?.minLength && value.trim().length < prompt.minLength) {
            setError(prompt.label + ' minimal ' + prompt.minLength + ' karakter.');
            return;
        }

        onClose({
            confirmed: true,
            value,
            ...(prompt?.name ? { [prompt.name]: value } : {}),
        });
    };

    return (
        <div
            className="fixed inset-0 z-[90] flex items-start justify-end bg-slate-950/20 p-4 backdrop-blur-[2px] sm:p-6"
            onMouseDown={(event) => event.target === event.currentTarget && onClose({ confirmed: false })}
        >
            <section
                className="app-popup-enter w-full max-w-md overflow-hidden rounded-2xl border border-border/80 bg-card shadow-2xl shadow-slate-950/20"
                role="alertdialog"
                aria-modal="true"
                aria-labelledby={'feedback-dialog-title-' + request.id}
                aria-describedby={'feedback-dialog-description-' + request.id}
            >
                <form onSubmit={submit}>
                    <div className="flex items-start gap-3 border-b border-border/70 px-5 py-4">
                        <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary">
                            <MessageCircleQuestion className="size-5" />
                        </span>
                        <div className="min-w-0 flex-1">
                            <h2 id={'feedback-dialog-title-' + request.id} className="text-sm font-semibold text-card-foreground">
                                {request.title ?? 'Konfirmasi tindakan'}
                            </h2>
                            <p id={'feedback-dialog-description-' + request.id} className="mt-1 text-sm leading-5 text-muted-foreground">
                                {request.description}
                            </p>
                        </div>
                        <button
                            type="button"
                            onClick={() => onClose({ confirmed: false })}
                            className="rounded-lg p-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            aria-label="Tutup dialog"
                        >
                            <X className="size-4" />
                        </button>
                    </div>

                    {prompt && (
                        <div className="px-5 pt-4">
                            <label htmlFor={'feedback-dialog-input-' + request.id} className="mb-2 block text-sm font-medium text-card-foreground">
                                {prompt.label}
                            </label>
                            <Input
                                id={'feedback-dialog-input-' + request.id}
                                value={value}
                                onChange={(event) => {
                                    setValue(event.target.value);
                                    setError('');
                                }}
                                placeholder={prompt.placeholder}
                                autoFocus
                                aria-invalid={Boolean(error)}
                            />
                            {error && <p className="mt-1.5 text-xs font-medium text-destructive">{error}</p>}
                        </div>
                    )}

                    <div className="flex justify-end gap-2 px-5 py-4">
                        <Button type="button" variant="outline" onClick={() => onClose({ confirmed: false })}>
                            {request.cancelLabel ?? 'Batal'}
                        </Button>
                        <Button type="submit" autoFocus={!prompt} variant={request.variant === 'destructive' ? 'destructive' : 'default'}>
                            {request.confirmLabel ?? 'Lanjutkan'}
                        </Button>
                    </div>
                </form>
            </section>
        </div>
    );
}

export function FeedbackProvider({ children }) {
    const [dialog, setDialog] = useState(null);
    const [toast, setToast] = useState(null);
    const toastTimer = useRef(null);

    const closeToast = () => {
        window.clearTimeout(toastTimer.current);
        setToast(null);
    };

    const showToast = (message, tone = 'success') => {
        if (!message) return;

        window.clearTimeout(toastTimer.current);
        setToast({ id: Date.now(), message, tone });
        toastTimer.current = window.setTimeout(() => setToast(null), 5000);
    };

    useEffect(() => {
        const removeSuccessListener = router.on('success', (event) => {
            const flash = event.detail.page?.props?.flash ?? {};
            const message = flash.error ?? flash.success;
            if (message) showToast(message, flash.error ? 'error' : 'success');
        });

        return () => removeSuccessListener();
    }, []);

    useEffect(() => () => window.clearTimeout(toastTimer.current), []);

    const requestAction = (options) => new Promise((resolve) => {
        setDialog({
            ...options,
            id: String(Date.now()) + '-' + Math.random().toString(36).slice(2),
            resolve,
        });
    });

    const closeDialog = (result) => {
        dialog?.resolve(result);
        setDialog(null);
    };

    return (
        <FeedbackContext.Provider value={{ requestAction, showToast }}>
            {children}
            <div className="pointer-events-none fixed inset-x-0 top-4 z-[100] flex justify-center px-4 sm:justify-end sm:px-6">
                {toast && <Toast key={toast.id} toast={toast} onClose={closeToast} />}
            </div>
            {dialog && <ActionDialog key={dialog.id} request={dialog} onClose={closeDialog} />}
        </FeedbackContext.Provider>
    );
}

export function useFeedback() {
    const context = useContext(FeedbackContext);

    if (!context) {
        throw new Error('useFeedback harus digunakan di dalam FeedbackProvider.');
    }

    return context;
}
