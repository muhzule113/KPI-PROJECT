import { BellIcon } from "@phosphor-icons/react/dist/ssr";
import Link from "next/link";
import { Pagination } from "@/components/pagination";
import { PageHeader, EmptyState } from "@/components/page-elements";
import { Button } from "@/components/ui/button";
import { markAllNotificationsRead, markNotificationRead } from "@/app/(workspace)/app/notifikasi/actions";
import { prisma } from "@/lib/prisma";
import { getPageCount, PAGINATION_PAGE_SIZE, parsePage } from "@/lib/pagination";
import { formatDate } from "@/lib/utils";
import { requireUser } from "@/modules/access/current-user";

export default async function NotificationsPage({ searchParams }: { searchParams: Promise<{ page?: string }> }) {
  const user = await requireUser();
  const query = await searchParams;
  const where = { userId: user.userId };
  const [totalNotifications, unread] = await Promise.all([
    prisma.notification.count({ where }),
    prisma.notification.count({ where: { ...where, readAt: null } }),
  ]);
  const totalPages = getPageCount(totalNotifications);
  const page = Math.min(parsePage(query.page), totalPages);
  const notifications = await prisma.notification.findMany({ where, orderBy: { createdAt: "desc" }, skip: (page - 1) * PAGINATION_PAGE_SIZE, take: PAGINATION_PAGE_SIZE });
  const firstNotification = totalNotifications ? (page - 1) * PAGINATION_PAGE_SIZE + 1 : 0;
  const lastNotification = Math.min(page * PAGINATION_PAGE_SIZE, totalNotifications);
  return <><PageHeader eyebrow="Pemberitahuan" title="Notifikasi" description="Perubahan status dan pekerjaan yang perlu Anda tindak lanjuti." actions={unread ? <form action={markAllNotificationsRead}><Button type="submit" variant="secondary">Tandai semua dibaca</Button></form> : null} />
    {!notifications.length ? <EmptyState icon={BellIcon} title="Belum ada notifikasi" description="Pemberitahuan periode, review, dan finalisasi akan muncul di sini." /> : <section className="panel"><div className="panel-header"><div><h2>{unread} belum dibaca</h2><p>Menampilkan {firstNotification}–{lastNotification} dari {totalNotifications} notifikasi</p></div></div><div className="notification-list">{notifications.map((item) => <article className={`notification-item${item.readAt ? "" : " unread"}`} key={item.id}><div className="sheet-head"><div><strong>{item.title}</strong><p className="notification-body">{item.body}</p><span className="cell-subtitle">{formatDate(item.createdAt, { dateStyle: undefined, date: undefined, timeStyle: "short" } as Intl.DateTimeFormatOptions)}</span></div><div className="form-actions notification-actions">{item.actionUrl ? <Button asChild size="small" variant="secondary"><Link href={item.actionUrl}>Buka</Link></Button> : null}{!item.readAt ? <form action={markNotificationRead}><input type="hidden" name="notificationId" value={item.id} /><Button type="submit" size="small" variant="ghost">Tandai dibaca</Button></form> : null}</div></div></article>)}</div><Pagination page={page} totalPages={totalPages} hrefForPage={(nextPage) => `/app/notifikasi?page=${nextPage}`} /></section>}
  </>;
}
