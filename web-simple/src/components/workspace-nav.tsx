"use client";

import * as Dialog from "@radix-ui/react-dialog";
import {
  BellIcon,
  BriefcaseIcon,
  BuildingsIcon,
  CalendarDotsIcon,
  CaretDownIcon,
  ChartBarIcon,
  ChecksIcon,
  ClipboardTextIcon,
  HouseIcon,
  ListIcon,
  MedalIcon,
  SlidersHorizontalIcon,
  ShieldCheckIcon,
  SignOutIcon,
  UsersIcon,
  XIcon,
  type Icon,
} from "@phosphor-icons/react";
import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useState, type ReactNode } from "react";
import { authClient } from "@/lib/auth-client";
import { cn } from "@/lib/utils";
import { ThemeToggle } from "@/components/theme-toggle";
import type { UserRole } from "@/modules/access/policy";

type NavItem = {
  href: string;
  label: string;
  icon: Icon;
  roles: UserRole[];
};

const allRoles: UserRole[] = ["ADMIN", "MANAGER", "SUPERVISOR", "EMPLOYEE"];
const roleLabels: Record<UserRole, string> = {
  ADMIN: "Super Admin",
  MANAGER: "Manager",
  SUPERVISOR: "Supervisor",
  EMPLOYEE: "Pegawai",
};

const navItems: NavItem[] = [
  { href: "/app", label: "Ringkasan", icon: HouseIcon, roles: allRoles },
  { href: "/app/harian", label: "Penilaian", icon: ClipboardTextIcon, roles: ["MANAGER", "SUPERVISOR"] },
  { href: "/app/review", label: "Review", icon: ChecksIcon, roles: ["MANAGER"] },
  { href: "/app/rekap", label: "Rekap", icon: ChartBarIcon, roles: ["ADMIN", "MANAGER", "SUPERVISOR"] },
  { href: "/app/kpi-saya", label: "KPI Saya", icon: ChartBarIcon, roles: ["EMPLOYEE"] },
  { href: "/app/pengaturan/cabang", label: "Cabang", icon: BuildingsIcon, roles: ["ADMIN"] },
  { href: "/app/pengaturan/jabatan", label: "Jabatan", icon: BriefcaseIcon, roles: ["ADMIN"] },
  { href: "/app/pengaturan/pengguna", label: "Pengguna", icon: UsersIcon, roles: ["ADMIN"] },
  { href: "/app/pengaturan/indikator", label: "Indikator KPI", icon: SlidersHorizontalIcon, roles: ["ADMIN"] },
  { href: "/app/pengaturan/predikat", label: "Predikat Nilai", icon: MedalIcon, roles: ["ADMIN"] },
  { href: "/app/pengaturan/periode", label: "Periode", icon: CalendarDotsIcon, roles: ["ADMIN"] },
  { href: "/app/audit", label: "Audit", icon: ShieldCheckIcon, roles: ["ADMIN"] },
  { href: "/app/notifikasi", label: "Notifikasi", icon: BellIcon, roles: allRoles },
];

const mobilePriorities: Record<UserRole, string[]> = {
  ADMIN: ["/app", "/app/pengaturan/indikator", "/app/rekap"],
  MANAGER: ["/app", "/app/harian", "/app/review"],
  SUPERVISOR: ["/app", "/app/harian", "/app/rekap"],
  EMPLOYEE: ["/app", "/app/kpi-saya", "/app/notifikasi"],
};

export function WorkspaceShell({ role, unread, userName, branchName, children }: {
  role: UserRole;
  unread: number;
  userName: string;
  branchName: string;
  children: ReactNode;
}) {
  const pathname = usePathname();
  const router = useRouter();
  const [menuOpen, setMenuOpen] = useState(false);
  const [signingOut, setSigningOut] = useState(false);
  const items = navItems.filter((item) => item.roles.includes(role));
  const mobileItems = mobilePriorities[role].map((href) => navItems.find((item) => item.href === href)!);
  const mobileHasActiveItem = mobileItems.some((item) => isActive(pathname, item.href));
  const activeItem = [...items].reverse().find((item) => isActive(pathname, item.href)) ?? items[0];
  const roleContext = `${roleLabels[role]} / ${branchName}`;

  async function signOut() {
    setSigningOut(true);
    await authClient.signOut();
    router.replace("/login");
    router.refresh();
  }

  return (
    <div className="workspace">
      <a className="skip-link" href="#main-content">Lewati ke konten utama</a>
      <header className="workbench-header">
        <div className="workbench-primary">
          <Brand />
          <div className="topbar-heading">
            <strong>{activeItem.label}</strong>
            <span>{roleContext}</span>
          </div>
          <div className="topbar-actions">
            <Link className={cn("notification-link", isActive(pathname, "/app/notifikasi") && "active")} href="/app/notifikasi" aria-label={unread ? `${unread} notifikasi belum dibaca` : "Notifikasi"} aria-current={isActive(pathname, "/app/notifikasi") ? "page" : undefined}>
              <BellIcon size={20} weight={isActive(pathname, "/app/notifikasi") ? "fill" : "regular"} aria-hidden="true" />
              {unread > 0 ? <span className="notification-count">{unread > 99 ? "99+" : unread}</span> : null}
            </Link>
            <details key={pathname} className="account-menu" suppressHydrationWarning onKeyDown={(event) => {
              if (event.key === "Escape") {
                event.currentTarget.open = false;
                event.currentTarget.querySelector<HTMLElement>("summary")?.focus();
              }
            }}>
              <summary className="account-trigger" aria-label={`Menu akun ${userName}`}>
                <span className="user-avatar" aria-hidden="true">{initials(userName)}</span>
                <span className="account-copy"><strong>{userName}</strong><small>{roleLabels[role]}</small></span>
                <CaretDownIcon size={16} aria-hidden="true" />
              </summary>
              <div className="account-panel">
                <div className="account-panel-copy"><strong>{userName}</strong><span>{roleContext}</span></div>
                <ThemeToggle />
                <button className="nav-link account-signout" type="button" onClick={signOut} disabled={signingOut}>
                  <SignOutIcon size={19} aria-hidden="true" /><span>{signingOut ? "Keluar..." : "Keluar"}</span>
                </button>
              </div>
            </details>
          </div>
        </div>
        <nav className="desktop-navigation" aria-label="Navigasi utama">
          <NavList items={items.filter((item) => item.href !== "/app/notifikasi")} pathname={pathname} unread={unread} horizontal />
        </nav>
      </header>

      <main className="workspace-main" id="main-content">
        <div className="workspace-content">{children}</div>
      </main>

      <Dialog.Root open={menuOpen} onOpenChange={setMenuOpen}>
        <nav className="mobile-nav" aria-label="Navigasi utama seluler">
          {mobileItems.map((item) => <MobileNavLink key={item.href} item={item} pathname={pathname} unread={unread} />)}
          <Dialog.Trigger asChild>
            <button className={cn("mobile-nav-link", (menuOpen || !mobileHasActiveItem) && "active")} type="button" aria-label="Buka semua menu">
              <span className="mobile-nav-icon-wrap">
                <ListIcon size={20} weight={(menuOpen || !mobileHasActiveItem) ? "bold" : "regular"} aria-hidden="true" />
              </span>
              <span>Menu</span>
            </button>
          </Dialog.Trigger>
        </nav>
        <Dialog.Portal>
          <Dialog.Overlay className="mobile-drawer-overlay" />
          <Dialog.Content className="mobile-drawer">
            <Dialog.Title className="sr-only">Menu aplikasi</Dialog.Title>
            <Dialog.Description className="sr-only">Pilih halaman aplikasi KPI Harian.</Dialog.Description>
            <div className="mobile-drawer-header">
              <Brand />
              <Dialog.Close className="drawer-close" aria-label="Tutup menu"><XIcon size={20} aria-hidden="true" /></Dialog.Close>
            </div>
            <nav className="mobile-drawer-body" aria-label="Semua halaman">
              <NavList items={items} pathname={pathname} unread={unread} onNavigate={() => setMenuOpen(false)} />
            </nav>
            <div className="mobile-drawer-footer">
              <UserPanel name={userName} context={roleContext} initials={initials(userName)} onSignOut={signOut} busy={signingOut} />
            </div>
          </Dialog.Content>
        </Dialog.Portal>
      </Dialog.Root>
    </div>
  );
}

function Brand() {
  return <Link className="sidebar-brand" href="/app"><span className="brand-mark">K</span><span><strong>KPI Harian</strong><small>Penilaian manual</small></span></Link>;
}

function NavList({ items, pathname, unread, onNavigate, horizontal = false }: { items: NavItem[]; pathname: string; unread: number; onNavigate?: () => void; horizontal?: boolean }) {
  return <div className={cn("nav-list", horizontal && "nav-list-horizontal")}>{items.map((item) => {
    const Icon = item.icon;
    const active = isActive(pathname, item.href);
    return <Link key={item.href} href={item.href} onClick={onNavigate} className={cn("nav-link", active && "active")} aria-current={active ? "page" : undefined} title={horizontal ? item.label : undefined}>
      <Icon size={19} weight={active ? "fill" : "regular"} aria-hidden="true" />
      <span>{item.label}</span>
      {item.href === "/app/notifikasi" && unread > 0 ? <span className="nav-count">{unread > 99 ? "99+" : unread}</span> : null}
    </Link>;
  })}</div>;
}

function MobileNavLink({ item, pathname, unread }: { item: NavItem; pathname: string; unread: number }) {
  const Icon = item.icon;
  const active = isActive(pathname, item.href);
  return <Link href={item.href} className={cn("mobile-nav-link", active && "active")} aria-current={active ? "page" : undefined}>
    <span className="mobile-nav-icon-wrap">
      <Icon size={20} weight={active ? "fill" : "regular"} aria-hidden="true" />
      {item.href === "/app/notifikasi" && unread > 0 ? <span className="notification-count">{unread > 99 ? "99+" : unread}</span> : null}
    </span>
    <span>{item.label}</span>
  </Link>;
}

function UserPanel({ name, context, initials: value, onSignOut, busy }: {
  name: string;
  context: string;
  initials: string;
  onSignOut: () => Promise<void>;
  busy: boolean;
}) {
  return <div className="sidebar-user">
    <div className="sidebar-profile"><span className="user-avatar" aria-hidden="true">{value}</span><div><strong>{name}</strong><span>{context}</span></div></div>
    <button className="nav-link account-signout" type="button" onClick={onSignOut} disabled={busy}>
      <SignOutIcon size={19} aria-hidden="true" /><span>{busy ? "Keluar..." : "Keluar"}</span>
    </button>
  </div>;
}

function isActive(pathname: string, href: string) {
  return href === "/app" ? pathname === href : pathname.startsWith(href);
}

function initials(name: string) {
  return name.trim().split(/\s+/).slice(0, 2).map((part) => part[0]).join("").toUpperCase();
}
