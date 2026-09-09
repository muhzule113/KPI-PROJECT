import { BuildingsIcon, DevicesIcon, EnvelopeIcon, IdentificationBadgeIcon, ShieldCheckIcon, UserCircleIcon } from "@phosphor-icons/react/dist/ssr";
import type { Metadata } from "next";
import { headers } from "next/headers";
import { redirect } from "next/navigation";
import { revokeOtherSessions, revokeOwnSession } from "@/app/(workspace)/app/profil/actions";
import { PageHeading } from "@/components/page-heading";
import { PasswordForm } from "@/components/profile/password-form";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { auth } from "@/lib/auth";
import { initials } from "@/lib/format";
import { prisma } from "@/lib/prisma";
import { requireUser } from "@/modules/access/current-user";

export const metadata: Metadata = { title: "Profil" };

const roleLabels = { super_admin: "Super Admin", kpi_admin: "Admin KPI", auditor: "Auditor", owner_manager: "Owner / Manajer", supervisor: "Supervisor", employee: "Karyawan" } as const;
const sessionDate = new Intl.DateTimeFormat("id-ID", { dateStyle: "medium", timeStyle: "short", timeZone: "Asia/Makassar" });

export default async function ProfilePage() {
  const user = await requireUser();
  const current = await auth.api.getSession({ headers: await headers() });
  if (!current) redirect("/login");
  const sessions = await prisma.session.findMany({ where: { userId: user.id, expiresAt: { gt: new Date() } }, orderBy: { updatedAt: "desc" }, select: { id: true, updatedAt: true, expiresAt: true, ipAddress: true, userAgent: true } });

  return (
    <div className="space-y-7">
      <PageHeading eyebrow="Akun" title="Profil saya" description="Identitas, keamanan akun, dan sesi perangkat yang digunakan oleh sistem." />
      <Card><CardContent className="p-5 sm:p-7">
        <div className="flex items-center gap-4"><span className="grid size-16 shrink-0 place-items-center rounded-2xl bg-primary text-xl font-bold text-primary-foreground">{initials(user.name)}</span><div className="min-w-0"><h2 className="truncate text-xl font-semibold">{user.name}</h2><p className="mt-1 truncate text-sm text-muted-foreground">{user.email}</p><Badge className="mt-2"><ShieldCheckIcon /> {roleLabels[user.role]}</Badge></div></div>
        <dl className="mt-7 grid gap-5 border-t pt-6 sm:grid-cols-2">
          <div><dt className="flex items-center gap-2 text-xs text-muted-foreground"><EnvelopeIcon /> Email akun</dt><dd className="mt-1 text-sm font-medium">{user.email}</dd></div>
          <div><dt className="flex items-center gap-2 text-xs text-muted-foreground"><UserCircleIcon /> Status akun</dt><dd className="mt-1 text-sm font-medium">{user.isActive ? "Aktif" : "Nonaktif"}</dd></div>
          <div><dt className="flex items-center gap-2 text-xs text-muted-foreground"><IdentificationBadgeIcon /> Jabatan</dt><dd className="mt-1 text-sm font-medium">{user.employee?.position.name ?? "Akun administratif"}</dd></div>
          <div><dt className="flex items-center gap-2 text-xs text-muted-foreground"><BuildingsIcon /> Cabang</dt><dd className="mt-1 text-sm font-medium">{user.employee?.branch.name ?? "Semua cabang"}</dd></div>
        </dl>
      </CardContent></Card>

      <div className="grid gap-5 xl:grid-cols-2">
        <Card><CardHeader><CardTitle className="text-base">Ubah kata sandi</CardTitle></CardHeader><CardContent><PasswordForm /></CardContent></Card>
        <Card><CardHeader><div className="flex flex-wrap items-center justify-between gap-3"><CardTitle className="flex items-center gap-2 text-base"><DevicesIcon className="text-primary" /> Sesi aktif ({sessions.length})</CardTitle>{sessions.length > 1 ? <form action={revokeOtherSessions}><Button type="submit" size="sm" variant="outline">Cabut sesi lain</Button></form> : null}</div></CardHeader><CardContent className="space-y-3">
          {sessions.map((session) => {
            const isCurrent = session.id === current.session.id;
            return <div key={session.id} className="rounded-xl border p-4"><div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"><div className="min-w-0"><div className="flex flex-wrap items-center gap-2"><p className="font-medium">{isCurrent ? "Perangkat ini" : "Perangkat lain"}</p>{isCurrent ? <Badge>Sesi sekarang</Badge> : null}</div><p className="mt-1 break-words text-xs text-muted-foreground">{session.userAgent?.slice(0, 160) || "Perangkat tidak diketahui"}</p><p className="mt-2 text-xs text-muted-foreground">IP {session.ipAddress ?? "tidak tercatat"} · Aktif {sessionDate.format(session.updatedAt)} · Berakhir {sessionDate.format(session.expiresAt)}</p></div>{!isCurrent ? <form action={revokeOwnSession}><input type="hidden" name="sessionId" value={session.id} /><Button type="submit" size="sm" variant="outline">Cabut</Button></form> : null}</div></div>;
          })}
        </CardContent></Card>
      </div>
    </div>
  );
}
