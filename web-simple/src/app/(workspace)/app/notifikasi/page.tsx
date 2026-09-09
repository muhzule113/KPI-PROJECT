import { BellIcon } from "@phosphor-icons/react/dist/ssr";
import Link from "next/link";
import { PageHeader, EmptyState } from "@/components/page-elements";
import { Button } from "@/components/ui/button";
import { markAllNotificationsRead, markNotificationRead } from "@/app/(workspace)/app/notifikasi/actions";
import { prisma } from "@/lib/prisma";
import { formatDate } from "@/lib/utils";
import { requireUser } from "@/modules/access/current-user";

export default async function NotificationsPage() {
  const user = await requireUser();
  const notifications = await prisma.notification.findMany({ where: { userId: user.userId }, orderBy: { createdAt: "desc" }, take: 100 });
  const unread = notifications.filter((item) => !item.readAt).length;
  return <><PageHeader eyebrow="Pemberitahuan" title="Notifikasi" description="Perubahan status dan pekerjaan yang perlu Anda tindak lanjuti." actions={unread ? <form action={markAllNotificationsRead}><Button type="submit" variant="secondary">Tandai semua dibaca</Button></form> : null} />
    {!notifications.length ? <EmptyState icon={BellIcon} title="Belum ada notifikasi" description="Pemberitahuan periode, review, dan finalisasi akan muncul di sini." /> : <section className="panel"><div className="panel-header"><div><h2>{unread} belum dibaca</h2><p>Maksimal 100 notifikasi terbaru</p></div></div><div className="notification-list">{notifications.map((item) => <article className={`notification-item${item.readAt ? "" : " unread"}`} key={item.id}><div className="sheet-head"><div><strong>{item.title}</strong><p className="notification-body">{item.body}</p><span className="cell-subtitle">{formatDate(item.createdAt, { dateStyle: undefined, date: undefined, timeStyle: "short" } as Intl.DateTimeFormatOptions)}</span></div><div className="form-actions notification-actions">{item.actionUrl ? <Button asChild size="small" variant="secondary"><Link href={item.actionUrl}>Buka</Link></Button> : null}{!item.readAt ? <form action={markNotificationRead}><input type="hidden" name="notificationId" value={item.id} /><Button type="submit" size="small" variant="ghost">Tandai dibaca</Button></form> : null}</div></div></article>)}</div></section>}
  </>;
}
