import { Head, Link, router } from '@inertiajs/react';
import { Clipboard, ExternalLink, MessageSquareText, QrCode, Send, Star, TicketCheck } from 'lucide-react';
import { useEffect, useState } from 'react';
import { QRCodeSVG } from 'qrcode.react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';

const statusLabels = {
    intake: 'Diterima',
    diagnosing: 'Diagnosis',
    waiting_sparepart: 'Menunggu sparepart',
    in_progress: 'Dikerjakan',
    qc_ready: 'Siap QC',
    completed: 'Selesai',
    delivered: 'Diserahkan',
    cancelled: 'Dibatalkan',
};

export default function CustomerFeedback({ tickets = [], selected_ticket: selectedTicket, feedback_url: feedbackUrl, feedbacks = [], stats }) {
    const [ticketId, setTicketId] = useState(selectedTicket?.id ? String(selectedTicket.id) : '');
    const [copied, setCopied] = useState(false);

    useEffect(() => {
        setTicketId(selectedTicket?.id ? String(selectedTicket.id) : '');
    }, [selectedTicket?.id]);

    const generateQr = (event) => {
        event.preventDefault();
        router.get('/app/customer-feedback', ticketId ? { ticket: ticketId } : {}, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    const copyUrl = async () => {
        if (!feedbackUrl || !navigator.clipboard) return;
        await navigator.clipboard.writeText(feedbackUrl);
        setCopied(true);
        window.setTimeout(() => setCopied(false), 1600);
    };

    const pelayanAverage = stats?.pelayan_average ?? stats?.average ?? 0;
    const technicianAverage = stats?.technician_average;

    return (
        <>
            <Head title="Progres Servis dan Feedback" />
            <div className="min-h-[calc(100dvh-76px)] bg-muted/15 px-4 py-6 sm:px-6 lg:px-8">
                <div className="mx-auto max-w-[1280px] space-y-6">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <Link href="/app" className="mb-3 inline-flex items-center gap-1.5 text-xs font-medium text-muted-foreground transition-colors hover:text-foreground">
                                ← Dashboard
                            </Link>
                            <div className="flex flex-wrap items-center gap-2">
                                <h1 className="text-2xl font-semibold tracking-tight text-foreground sm:text-3xl">Progres Servis dan Feedback</h1>
                                <Badge variant="secondary">{stats?.total ?? 0} masuk</Badge>
                            </div>
                            <p className="mt-1.5 max-w-2xl text-sm text-muted-foreground">Bagikan satu QR Code agar pelanggan dapat memantau servis dan memberi penilaian setelah perangkat diserahkan.</p>
                        </div>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <StatCard icon={MessageSquareText} label="Feedback masuk" value={stats?.total ?? 0} />
                        <StatCard icon={Star} label="Rata-rata Pelayan" value={`${pelayanAverage} / 5`} />
                        <StatCard icon={Star} label="Rata-rata Teknisi" value={technicianAverage === null || technicianAverage === undefined ? 'Belum ada' : `${technicianAverage} / 5`} />
                        <StatCard icon={TicketCheck} label="Menunggu feedback" value={stats?.pending ?? 0} />
                    </div>

                    <div className="grid items-start gap-6 xl:grid-cols-[minmax(0,0.95fr)_minmax(420px,1.05fr)]">
                        <Card className="shadow-sm shadow-slate-950/5">
                            <CardHeader className="border-b border-border/70">
                                <div className="flex items-center gap-3">
                                    <span className="flex size-10 items-center justify-center rounded-xl bg-primary/10 text-primary"><QrCode className="size-5" /></span>
                                    <div>
                                        <CardTitle>Buat QR progres servis</CardTitle>
                                        <CardDescription className="mt-1">Pilih tiket yang tautannya masih dapat dibagikan.</CardDescription>
                                    </div>
                                </div>
                            </CardHeader>
                            <CardContent className="space-y-5 pt-5">
                                <form onSubmit={generateQr} className="space-y-3">
                                    <label htmlFor="feedback-ticket" className="block text-sm font-medium text-foreground">Tiket servis</label>
                                    <select
                                        id="feedback-ticket"
                                        value={ticketId}
                                        onChange={(event) => setTicketId(event.target.value)}
                                        className="flex h-11 w-full rounded-lg border border-input bg-background px-3 text-sm text-foreground outline-none transition-colors focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
                                    >
                                        <option value="">Pilih tiket...</option>
                                        {tickets.map((ticket) => (
                                            <option key={ticket.id} value={ticket.id}>
                                                {ticket.ticket_number} · {ticket.customer_name} · {ticket.device} ({statusLabels[ticket.status] ?? ticket.status})
                                            </option>
                                        ))}
                                    </select>
                                    <Button type="submit" disabled={!ticketId} className="min-h-11 w-full sm:w-auto">
                                        <QrCode />
                                        Tampilkan QR Code
                                    </Button>
                                </form>

                                {tickets.length === 0 && (
                                    <div className="rounded-xl border border-dashed border-border bg-muted/25 p-4 text-sm text-muted-foreground">
                                        Tidak ada tiket yang tautan progresnya masih dapat dibagikan.
                                    </div>
                                )}

                                <div className="rounded-xl border border-border bg-muted/20 p-4 text-sm text-muted-foreground">
                                    <p className="font-medium text-foreground">Satu tautan untuk seluruh proses</p>
                                    <p className="mt-1 leading-6">Bagikan QR ini saat tiket dibuat. Pelanggan memakai tautan yang sama untuk melihat progres dan memberikan feedback setelah penyerahan.</p>
                                </div>
                            </CardContent>
                        </Card>

                        <Card className="overflow-hidden shadow-sm shadow-slate-950/5">
                            <CardHeader className="border-b border-border/70">
                                <CardTitle>QR Code pelanggan</CardTitle>
                                <CardDescription>{selectedTicket ? `${selectedTicket.ticket_number} · ${selectedTicket.customer_name}` : 'QR akan tampil setelah tiket dipilih.'}</CardDescription>
                            </CardHeader>
                            <CardContent className="flex min-h-[360px] flex-col items-center justify-center gap-5 p-6">
                                {feedbackUrl ? (
                                    <>
                                        <div className="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
                                            <QRCodeSVG value={feedbackUrl} size={236} includeMargin level="M" fgColor="#172554" />
                                        </div>
                                        <div className="flex flex-wrap justify-center gap-2">
                                            <Button type="button" variant="outline" onClick={copyUrl} className="min-h-11">
                                                <Clipboard />
                                                {copied ? 'Tersalin' : 'Salin tautan'}
                                            </Button>
                                            <Button asChild variant="outline" className="min-h-11">
                                                <a href={feedbackUrl} target="_blank" rel="noreferrer"><ExternalLink /> Buka halaman</a>
                                            </Button>
                                        </div>
                                        <p className="max-w-sm text-center text-xs leading-5 text-muted-foreground">Pelanggan dapat menyimpan tautan ini dan membukanya kembali untuk melihat status servis terbaru.</p>
                                    </>
                                ) : (
                                    <div className="text-center">
                                        <span className="mx-auto flex size-14 items-center justify-center rounded-2xl bg-muted text-muted-foreground"><QrCode className="size-7" /></span>
                                        <p className="mt-4 text-sm font-medium text-foreground">Belum ada QR Code</p>
                                        <p className="mt-1 max-w-xs text-sm leading-6 text-muted-foreground">Pilih tiket di panel sebelah untuk menampilkan tautan progres.</p>
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </div>

                    <Card className="overflow-hidden shadow-sm shadow-slate-950/5">
                        <CardHeader className="border-b border-border/70">
                            <div className="flex items-center gap-3">
                                <span className="flex size-10 items-center justify-center rounded-xl bg-primary/10 text-primary"><Send className="size-5" /></span>
                                <div>
                                    <CardTitle>Feedback terbaru</CardTitle>
                                    <CardDescription>Rating Pelayan dan Teknisi yang sudah tercatat di sistem.</CardDescription>
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent className="p-0">
                            {feedbacks.length === 0 ? (
                                <div className="px-6 py-12 text-center text-sm text-muted-foreground">Belum ada feedback pelanggan. Feedback akan tampil setelah customer mengirim penilaian.</div>
                            ) : (
                                <div className="divide-y divide-border/70">
                                    {feedbacks.map((feedback) => (
                                        <FeedbackRow key={feedback.id} feedback={feedback} />
                                    ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </>
    );
}

function FeedbackRow({ feedback }) {
    const pelayanRating = feedback.pelayan_rating ?? feedback.rating;
    const pelayanEmployee = feedback.pelayan_employee ?? feedback.employee;
    const technicianEmployee = feedback.technician_employee ?? feedback.technician;

    return (
        <div className="grid gap-4 px-6 py-4 lg:grid-cols-[minmax(0,1fr)_minmax(280px,0.65fr)]">
            <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-2">
                    <p className="font-medium text-foreground">{feedback.customer_name}</p>
                    <Badge variant="outline">{feedback.ticket_number ?? 'Tanpa tiket'}</Badge>
                </div>
                <p className="mt-1 text-sm text-muted-foreground">{feedback.comments || 'Tidak ada komentar.'}</p>
                <p className="mt-2 text-xs text-muted-foreground">{feedback.created_at}</p>
            </div>
            <div className="space-y-3">
                <RatingSummary label="Pelayan" employee={pelayanEmployee} rating={pelayanRating} />
                {feedback.technician_rating !== null && feedback.technician_rating !== undefined && (
                    <RatingSummary label="Teknisi" employee={technicianEmployee} rating={feedback.technician_rating} />
                )}
            </div>
        </div>
    );
}

function RatingSummary({ label, employee, rating }) {
    return (
        <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
            <p className="min-w-0 text-muted-foreground"><span className="font-medium text-foreground">{label}:</span> {employee || 'Petugas tidak tercatat'}</p>
            <div className="flex shrink-0 items-center gap-1 text-amber-500" aria-label={`Rating ${label} ${rating} dari 5`}>
                {Array.from({ length: 5 }, (_, index) => <Star key={index} className="size-4" fill={index < rating ? 'currentColor' : 'none'} />)}
            </div>
        </div>
    );
}

function StatCard({ icon: Icon, label, value }) {
    return (
        <Card className="shadow-sm shadow-slate-950/5">
            <CardContent className="flex items-center gap-3 p-4">
                <span className="flex size-10 items-center justify-center rounded-xl bg-primary/10 text-primary"><Icon className="size-5" /></span>
                <div>
                    <p className="text-xs text-muted-foreground">{label}</p>
                    <p className="mt-0.5 text-xl font-semibold tracking-tight text-foreground">{value}</p>
                </div>
            </CardContent>
        </Card>
    );
}
