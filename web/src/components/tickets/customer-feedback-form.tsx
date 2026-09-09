"use client";

import { CheckCircleIcon, CopyIcon, SpinnerGapIcon } from "@phosphor-icons/react";
import { useActionState, useState } from "react";
import { submitCustomerFeedback, type CustomerFeedbackState } from "@/app/feedback/[token]/actions";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";

const initialState: CustomerFeedbackState = {};
const selectClass = "flex h-11 w-full rounded-[10px] border border-input bg-background px-3 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring";

export function CustomerFeedbackForm({ token, hasTechnician }: { token: string; hasTechnician: boolean }) {
  const [state, action, pending] = useActionState(submitCustomerFeedback, initialState);
  return <form action={action} className="space-y-5"><input type="hidden" name="token" value={token} /><div className="space-y-2"><Label htmlFor="rating">Pelayanan dari Pelayan</Label><select id="rating" name="rating" required defaultValue="" className={selectClass}><option value="" disabled>Pilih rating 1–5</option>{[5, 4, 3, 2, 1].map((rating) => <option key={rating} value={rating}>{rating} — {rating >= 4 ? "Puas" : rating === 3 ? "Cukup" : "Tidak puas"}</option>)}</select></div>{hasTechnician ? <div className="space-y-2"><Label htmlFor="technicianRating">Pelayanan dari Teknisi</Label><select id="technicianRating" name="technicianRating" required defaultValue="" className={selectClass}><option value="" disabled>Pilih rating 1–5</option>{[5, 4, 3, 2, 1].map((rating) => <option key={rating} value={rating}>{rating}</option>)}</select></div> : null}<div className="space-y-2"><Label htmlFor="comments">Komentar (opsional)</Label><Textarea id="comments" name="comments" maxLength={2000} rows={4} /></div><Button type="submit" className="w-full" disabled={pending}>{pending ? <SpinnerGapIcon className="animate-spin" /> : <CheckCircleIcon />}{pending ? "Mengirim..." : "Kirim feedback"}</Button>{state.error ? <p role="alert" className="rounded-lg bg-destructive/10 px-3 py-2 text-sm text-destructive">{state.error}</p> : null}{state.success ? <p role="status" className="rounded-lg bg-accent px-3 py-2 text-sm text-primary">{state.success}</p> : null}</form>;
}

export function FeedbackShareLink({ href }: { href: string }) {
  const [copied, setCopied] = useState(false);
  async function copy() {
    await navigator.clipboard.writeText(new URL(href, window.location.origin).href);
    setCopied(true);
  }
  return <div className="rounded-2xl border bg-card p-5"><h2 className="text-base font-semibold">Tautan feedback pelanggan</h2><p className="mt-1 text-sm text-muted-foreground">Aktif selama tujuh hari sejak perangkat diserahkan.</p><div className="mt-4 flex flex-col gap-2 sm:flex-row"><code className="min-w-0 flex-1 truncate rounded-lg bg-muted px-3 py-3 text-xs">{href}</code><Button type="button" variant="outline" onClick={() => void copy()}><CopyIcon />{copied ? "Tersalin" : "Salin tautan"}</Button></div></div>;
}
