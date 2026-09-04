import { Head, useForm } from '@inertiajs/react';
import { Gauge, Loader2, LockKeyhole, Mail } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import DecorativeBackdrop from '@/components/layout/DecorativeBackdrop';

export default function Login() {
    const form = useForm({ email: '', password: '', remember: false });

    const submit = (event) => {
        event.preventDefault();
        form.post('/login');
    };

    return (
        <>
            <Head title="Masuk" />
            <main className="app-page-enter relative isolate flex min-h-[100dvh] items-center justify-center overflow-x-hidden auth-surface px-4 py-8 text-slate-900" aria-busy={form.processing}>
                <DecorativeBackdrop />
                {form.processing && (
                    <div className="pointer-events-none fixed inset-x-0 top-0 z-[100] h-1 bg-indigo-400/20" role="status" aria-live="polite">
                        <span className="sr-only">Sedang masuk...</span>
                        <div className="app-loading-sweep h-full w-1/3 rounded-r-full bg-indigo-400 shadow-[0_0_18px_rgba(129,140,248,0.85)]" />
                    </div>
                )}
                <div className="relative z-10 grid w-full max-w-4xl overflow-hidden rounded-3xl border border-white/75 bg-white/70 shadow-2xl shadow-indigo-900/10 backdrop-blur-sm lg:grid-cols-[1.05fr_0.95fr]">
                    <div className="hidden flex-col justify-between bg-gradient-to-br from-indigo-500 via-indigo-600 to-blue-700 p-10 text-white lg:flex">
                        <div className="flex items-center gap-3">
                            <span className="flex size-10 items-center justify-center rounded-xl bg-white/15"><Gauge className="size-5" /></span>
                            <span className="text-sm font-semibold tracking-wide">KPI System</span>
                        </div>
                        <div>
                            <p className="mb-4 text-xs font-semibold uppercase tracking-[0.2em] text-indigo-200">Performance workspace</p>
                            <h1 className="max-w-md text-4xl font-semibold leading-tight tracking-tight">Satu ruang kerja untuk kinerja tim yang terukur.</h1>
                            <p className="mt-4 max-w-md text-sm leading-6 text-indigo-100/75">Kelola periode, operasional harian, dan alur review KPI dari satu dashboard yang rapi.</p>
                        </div>
                        <p className="text-xs text-indigo-100/80">Sistem Manajemen KPI Toko & Servis HP</p>
                    </div>

                    <div className="bg-white/90 p-6 text-foreground sm:p-10">
                        <div className="mb-8 lg:hidden">
                            <div className="flex items-center gap-3">
                                <span className="flex size-10 items-center justify-center rounded-xl bg-primary text-primary-foreground"><Gauge className="size-5" /></span>
                                <span className="font-semibold">KPI System</span>
                            </div>
                        </div>
                        <div className="mb-8">
                            <p className="text-sm font-medium text-primary">Selamat datang kembali</p>
                            <h2 className="mt-2 text-2xl font-semibold tracking-tight">Masuk ke dashboard</h2>
                            <p className="mt-2 text-sm text-muted-foreground">Gunakan akun kerja Anda untuk melanjutkan.</p>
                        </div>

                        <form onSubmit={submit} className="space-y-5">
                            <div>
                                <label htmlFor="email" className="mb-2 block text-sm font-medium">Email</label>
                                <div className="relative">
                                    <Mail className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                                    <Input id="email" type="email" value={form.data.email} onChange={(event) => form.setData('email', event.target.value)} className="h-10 pl-9" autoComplete="email" autoFocus disabled={form.processing} aria-invalid={Boolean(form.errors.email)} />
                                </div>
                                {form.errors.email && <p className="mt-1.5 text-xs font-medium text-destructive">{form.errors.email}</p>}
                            </div>
                            <div>
                                <label htmlFor="password" className="mb-2 block text-sm font-medium">Kata sandi</label>
                                <div className="relative">
                                    <LockKeyhole className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                                    <Input id="password" type="password" value={form.data.password} onChange={(event) => form.setData('password', event.target.value)} className="h-10 pl-9" autoComplete="current-password" disabled={form.processing} aria-invalid={Boolean(form.errors.password)} />
                                </div>
                                {form.errors.password && <p className="mt-1.5 text-xs font-medium text-destructive">{form.errors.password}</p>}
                            </div>
                            <label className="flex items-center gap-2 text-sm text-muted-foreground">
                                <input type="checkbox" checked={form.data.remember} onChange={(event) => form.setData('remember', event.target.checked)} disabled={form.processing} className="size-4 rounded border-input accent-primary" />
                                Ingat saya di perangkat ini
                            </label>
                            <Button type="submit" className="h-10 w-full" disabled={form.processing}>
                                {form.processing ? <><Loader2 className="animate-spin" />Memeriksa akun...</> : <>Masuk ke dashboard</>}
                            </Button>
                        </form>
                    </div>
                </div>
            </main>
        </>
    );
}
