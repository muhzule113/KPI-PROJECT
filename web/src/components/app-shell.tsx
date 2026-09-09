"use client";

import * as Dialog from "@radix-ui/react-dialog";
import * as DropdownMenu from "@radix-ui/react-dropdown-menu";
import {
  ArrowsClockwiseIcon,
  BellIcon,
  BriefcaseIcon,
  CaretDownIcon,
  ChartBarIcon,
  ChatCircleDotsIcon,
  CheckSquareOffsetIcon,
  ClockCounterClockwiseIcon,
  GaugeIcon,
  GearSixIcon,
  ListIcon,
  NotePencilIcon,
  SignOutIcon,
  StorefrontIcon,
  UserCircleIcon,
  UsersThreeIcon,
  WrenchIcon,
  XIcon,
} from "@phosphor-icons/react";
import type { Icon } from "@phosphor-icons/react";
import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useState } from "react";
import { Button } from "@/components/ui/button";
import { authClient } from "@/lib/auth-client";
import { initials } from "@/lib/format";
import { cn } from "@/lib/utils";
import { capabilitiesFor, type AccessProfile, type Role } from "@/modules/access/capabilities";

type NavItem = { href: string; label: string; icon: Icon; any?: readonly string[] };

const navItems: readonly NavItem[] = [
  { href: "/app", label: "Ringkasan", icon: GaugeIcon },
  { href: "/app/kpi-saya", label: "KPI saya", icon: ChartBarIcon, any: ["kpi.self.view"] },
  { href: "/app/tim", label: "KPI tim", icon: UsersThreeIcon, any: ["kpi.supervisor.review", "kpi.manager.approval", "kpi.monitor"] },
  { href: "/app/koreksi", label: "Koreksi KPI", icon: NotePencilIcon, any: ["kpi.correction.request", "kpi.correction.manage"] },
  { href: "/app/servis", label: "Servis", icon: WrenchIcon, any: ["tickets.view"] },
  { href: "/app/feedback", label: "Feedback", icon: ChatCircleDotsIcon, any: ["feedback.view"] },
  { href: "/app/operasional", label: "Operasional", icon: CheckSquareOffsetIcon, any: ["attendance.manage", "attendance.team.manage", "work-logs.manage", "complaints.manage", "complaints.create", "coaching.manage", "spareparts.manage", "stock-opname.manage"] },
  { href: "/app/impor", label: "Impor data", icon: ArrowsClockwiseIcon, any: ["imports.configure", "cashier.import"] },
  { href: "/app/laporan", label: "Laporan", icon: BriefcaseIcon, any: ["reports.view"] },
  { href: "/app/audit", label: "Audit", icon: ClockCounterClockwiseIcon, any: ["audit.view", "audit.sync.view"] },
  { href: "/app/pengaturan", label: "Pengaturan", icon: GearSixIcon, any: ["accounts.manage", "organization.manage", "kpi.catalog.configure", "kpi.assignments.manage", "kpi.period.manage", "imports.configure"] },
];

const roleLabels: Record<Role, string> = {
  super_admin: "Super Admin",
  kpi_admin: "Admin KPI",
  auditor: "Auditor",
  owner_manager: "Owner / Manajer",
  supervisor: "Supervisor",
  employee: "Karyawan",
};

function Navigation({ user, onNavigate }: { user: AccessProfile; onNavigate?: () => void }) {
  const pathname = usePathname();
  const capabilities = capabilitiesFor(user);
  const visibleItems = navItems.filter((item) => !item.any || item.any.some((capability) => capabilities.has(capability)));

  return (
    <nav aria-label="Navigasi utama" className="space-y-1">
      {visibleItems.map((item) => {
        const active = item.href === "/app" ? pathname === item.href : pathname.startsWith(item.href);
        return (
          <Link
            key={item.href}
            href={item.href}
            onClick={onNavigate}
            aria-current={active ? "page" : undefined}
            className={cn(
              "flex min-h-11 items-center gap-3 rounded-xl px-3 text-sm font-medium outline-none transition-colors focus-visible:ring-2 focus-visible:ring-primary",
              active ? "bg-primary text-primary-foreground shadow-sm" : "text-sidebar-foreground/70 hover:bg-sidebar-muted hover:text-sidebar-foreground",
            )}
          >
            <item.icon aria-hidden="true" size={20} weight={active ? "fill" : "regular"} />
            {item.label}
          </Link>
        );
      })}
    </nav>
  );
}

function Brand() {
  return (
    <Link href="/app" className="flex min-h-11 items-center gap-3 rounded-xl outline-none focus-visible:ring-2 focus-visible:ring-primary">
      <span className="grid size-10 place-items-center rounded-xl bg-primary text-primary-foreground shadow-sm">
        <StorefrontIcon aria-hidden="true" size={22} weight="duotone" />
      </span>
      <span>
        <span className="block text-sm font-bold tracking-[0.14em] text-sidebar-foreground">KPI OPS</span>
        <span className="block text-xs text-sidebar-foreground/55">Toko dan servis HP</span>
      </span>
    </Link>
  );
}

function UserMenu({ user }: { user: AccessProfile }) {
  const [busy, setBusy] = useState(false);
  const router = useRouter();

  async function signOut() {
    setBusy(true);
    await authClient.signOut({
      fetchOptions: {
        onSuccess: () => {
          router.push("/login");
          router.refresh();
        },
      },
    });
    setBusy(false);
  }

  return (
    <DropdownMenu.Root>
      <DropdownMenu.Trigger asChild>
        <Button variant="ghost" className="h-11 min-w-0 gap-2 px-2 sm:px-3" aria-label="Buka menu akun">
          <span className="grid size-8 shrink-0 place-items-center rounded-lg bg-primary text-xs font-bold text-primary-foreground">{initials(user.name)}</span>
          <span className="hidden min-w-0 text-left sm:block">
            <span className="block max-w-36 truncate text-sm leading-4">{user.name}</span>
            <span className="block text-xs font-normal text-muted-foreground">{roleLabels[user.role]}</span>
          </span>
          <CaretDownIcon aria-hidden="true" className="hidden sm:block" />
        </Button>
      </DropdownMenu.Trigger>
      <DropdownMenu.Portal>
        <DropdownMenu.Content align="end" sideOffset={8} className="z-50 w-64 rounded-xl border bg-card p-1.5 text-card-foreground shadow-xl outline-none">
          <div className="px-3 py-2">
            <p className="truncate text-sm font-semibold">{user.name}</p>
            <p className="truncate text-xs text-muted-foreground">{user.email}</p>
          </div>
          <DropdownMenu.Separator className="my-1 h-px bg-border" />
          <DropdownMenu.Item asChild>
            <Link className="flex min-h-10 cursor-pointer items-center gap-2 rounded-lg px-3 text-sm outline-none hover:bg-accent focus:bg-accent" href="/app/profil">
              <UserCircleIcon aria-hidden="true" /> Profil
            </Link>
          </DropdownMenu.Item>
          <DropdownMenu.Item
            disabled={busy}
            onSelect={(event) => {
              event.preventDefault();
              void signOut();
            }}
            className="flex min-h-10 cursor-pointer items-center gap-2 rounded-lg px-3 text-sm text-destructive outline-none hover:bg-destructive/10 focus:bg-destructive/10 disabled:opacity-50"
          >
            <SignOutIcon aria-hidden="true" /> {busy ? "Keluar..." : "Keluar"}
          </DropdownMenu.Item>
        </DropdownMenu.Content>
      </DropdownMenu.Portal>
    </DropdownMenu.Root>
  );
}

export function AppShell({ user, unreadNotifications, children }: { user: AccessProfile; unreadNotifications: number; children: React.ReactNode }) {
  const [menuOpen, setMenuOpen] = useState(false);
  const pathname = usePathname();
  const capabilities = capabilitiesFor(user);
  const visibleItems = navItems.filter((item) => !item.any || item.any.some((capability) => capabilities.has(capability)));
  const compactItems = visibleItems.filter((item) => item.href !== "/app/operasional").slice(0, 3);
  const pageTitle = [...visibleItems]
    .sort((a, b) => b.href.length - a.href.length)
    .find((item) => item.href === "/app" ? pathname === "/app" : pathname.startsWith(item.href))?.label ?? "KPI OPS";

  return (
    <Dialog.Root open={menuOpen} onOpenChange={setMenuOpen}>
      <div className="min-h-dvh bg-background lg:grid lg:grid-cols-[17rem_1fr]">
        <aside className="fixed inset-y-0 left-0 z-30 hidden w-68 flex-col border-r bg-sidebar px-4 py-5 lg:flex">
          <Brand />
          <div className="mt-8 flex-1 overflow-y-auto"><Navigation user={user} /></div>
          {user.employee ? (
            <div className="rounded-xl bg-sidebar-muted px-3 py-3 text-xs leading-5 text-sidebar-foreground/70">
              <p className="font-semibold text-sidebar-foreground">{user.employee.branch.name}</p>
              <p>{user.employee.position.name}</p>
            </div>
          ) : null}
        </aside>

        <div className="min-w-0 lg:col-start-2">
          <header className="sticky top-0 z-20 flex h-16 items-center justify-between border-b bg-background/92 px-4 backdrop-blur sm:px-6 lg:h-18 lg:px-8">
            <div className="flex min-w-0 items-center gap-2">
              <Dialog.Trigger asChild>
                <Button variant="ghost" size="icon" className="lg:hidden" aria-label="Buka navigasi"><ListIcon aria-hidden="true" /></Button>
              </Dialog.Trigger>
              <p className="truncate text-sm font-semibold sm:text-base">{pageTitle}</p>
            </div>
            <div className="flex items-center gap-1">
              <Button asChild variant="ghost" size="icon" className="relative" aria-label={unreadNotifications ? `${unreadNotifications} notifikasi belum dibaca` : "Notifikasi"}>
                <Link href="/app/notifikasi"><BellIcon aria-hidden="true" />{unreadNotifications ? <span className="absolute right-0.5 top-0.5 grid min-h-4 min-w-4 place-items-center rounded-full bg-destructive px-1 text-[10px] font-bold leading-4 text-white">{Math.min(unreadNotifications, 99)}</span> : null}</Link>
              </Button>
              <UserMenu user={user} />
            </div>
          </header>
          <main className="mx-auto w-full max-w-[1500px] px-4 py-6 pb-24 sm:px-6 lg:px-8 lg:py-8 lg:pb-10">{children}</main>
        </div>

        <nav aria-label="Navigasi cepat" className="fixed inset-x-0 bottom-0 z-30 grid h-[4.5rem] grid-cols-4 border-t bg-background/96 px-1 pb-[env(safe-area-inset-bottom)] backdrop-blur lg:hidden">
          {compactItems.map((item) => {
            const active = item.href === "/app" ? pathname === item.href : pathname.startsWith(item.href);
            return (
              <Link key={item.href} href={item.href} aria-current={active ? "page" : undefined} className={cn("flex min-h-11 flex-col items-center justify-center gap-1 rounded-lg text-[11px] font-medium", active ? "text-primary" : "text-muted-foreground")}>
                <item.icon aria-hidden="true" size={21} weight={active ? "fill" : "regular"} />
                <span>{item.label}</span>
              </Link>
            );
          })}
          <Dialog.Trigger className="flex min-h-11 flex-col items-center justify-center gap-1 rounded-lg text-[11px] font-medium text-muted-foreground outline-none focus-visible:ring-2 focus-visible:ring-ring">
            <ListIcon aria-hidden="true" size={21} /><span>Menu</span>
          </Dialog.Trigger>
        </nav>
      </div>

      <Dialog.Portal>
        <Dialog.Overlay className="fixed inset-0 z-40 bg-black/55 data-[state=closed]:animate-out data-[state=open]:animate-in data-[state=closed]:fade-out data-[state=open]:fade-in" />
        <Dialog.Content className="fixed inset-y-0 left-0 z-50 flex w-[min(88vw,20rem)] flex-col bg-sidebar px-4 py-5 shadow-2xl outline-none data-[state=closed]:animate-out data-[state=open]:animate-in data-[state=closed]:slide-out-to-left data-[state=open]:slide-in-from-left">
          <Dialog.Title className="sr-only">Navigasi aplikasi</Dialog.Title>
          <div className="flex items-center justify-between">
            <Brand />
            <Dialog.Close asChild><Button variant="ghost" size="icon" className="text-sidebar-foreground hover:bg-sidebar-muted" aria-label="Tutup navigasi"><XIcon aria-hidden="true" /></Button></Dialog.Close>
          </div>
          <div className="mt-7 flex-1 overflow-y-auto"><Navigation user={user} onNavigate={() => setMenuOpen(false)} /></div>
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  );
}
