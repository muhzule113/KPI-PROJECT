import { BellIcon, CheckIcon } from "@phosphor-icons/react/dist/ssr";
import type { Metadata } from "next";
import Link from "next/link";
import { EmptyState } from "@/components/empty-state";
import { PageHeading } from "@/components/page-heading";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { formatDate } from "@/lib/format";
import { prisma } from "@/lib/prisma";
import { requireUser } from "@/modules/access/current-user";
import { markAllNotificationsRead, markNotificationRead } from "./actions";

export const metadata: Metadata = { title: "Notifikasi" };

export default async function NotificationsPage() {
  const user = await requireUser();
  const notifications = await prisma.systemNotification.findMany({
    where: { userId: user.id },
    orderBy: { createdAt: "desc" },
    take: 50,
  });
  const unread = notifications.filter((notification) => !notification.isRead).length;

  return <div className="space-y-7">
    <PageHeading eyebrow="Kotak masuk" title="Notifikasi" description="Status tetap diambil dari data terbaru saat tautan dibuka." action={unread ? <form action={markAllNotificationsRead}><Button type="submit" variant="outline"><CheckIcon /> Tandai semua dibaca</Button></form> : undefined} />
    {notifications.length === 0 ? <EmptyState icon={BellIcon} title="Belum ada notifikasi" description="Pembaruan pekerjaan dan KPI akan tampil di sini." /> : <div className="space-y-3">{notifications.map((notification) => {
      const href = notification.actionUrl?.startsWith("/app") ? notification.actionUrl : null;
      return <Card key={notification.id} className={notification.isRead ? "opacity-75" : "border-primary/35"}><CardContent className="flex flex-col gap-4 p-5 sm:flex-row sm:items-start sm:justify-between"><div className="min-w-0"><div className="flex items-center gap-2"><p className="font-semibold">{notification.title}</p>{!notification.isRead ? <span className="size-2 shrink-0 rounded-full bg-primary" aria-label="Belum dibaca" /> : null}</div><p className="mt-1 text-sm leading-6 text-muted-foreground">{notification.body}</p><p className="mt-2 text-xs text-muted-foreground">{formatDate(notification.createdAt)}</p>{href ? <Button asChild variant="link" className="mt-2 h-auto p-0"><Link href={href}>Buka data terbaru</Link></Button> : null}</div>{!notification.isRead ? <form action={markNotificationRead}><input type="hidden" name="notificationId" value={notification.id} /><Button type="submit" size="sm" variant="outline"><CheckIcon /> Tandai dibaca</Button></form> : null}</CardContent></Card>;
    })}</div>}
  </div>;
}
