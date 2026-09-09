# AGENTS.md — KPI_PROJECT

Sistem Manajemen KPI untuk Toko & Servis HP. PRD lengkap: `PRD_Sistem_KPI_Toko_Servis_HP.md` (source of truth untuk aturan bisnis, formula KPI, dan role).

## Stack

| Bagian | Teknologi | Lokasi |
|---|---|---|
| Aplikasi utama | Next.js + TypeScript | `web/` |
| Database dan ORM | PostgreSQL + Prisma | `web/prisma/` |
| UI responsif | Tailwind CSS + shadcn/ui | `web/src/` |
| Login dan sesi | Better Auth | `web/src/lib/auth.ts` |
| Referensi legacy | Laravel + Flutter | `backend/`, `mobile/` |
| Bahasa produk | Indonesia | — |

## Struktur

```
web/                      # Aplikasi aktif untuk desktop dan mobile web
  src/app/                # App Router, halaman, route handler, server action
  src/modules/            # Aturan bisnis dan kontrol akses
  prisma/                 # Skema PostgreSQL dan seed
backend/                  # Laravel backend + React/Inertia admin di /app
  app/Models/             # Eloquent models
  app/Modules/            # Approval, Assessment, Calculation, Import, Period, Review
  app/Modules/Assessment/ # Sync services tiap subsistem KPI:
                          #   OperationalKpiSyncService (composite — tiket + absensi +
                          #   inventory + admin work-log + komplain + coaching + agregasi tim),
                          #   AttendanceKpiSyncService, InventoryKpiSyncService,
                          #   StockOpnameService, AdminWorkLogKpiSyncService,
                          #   ComplaintKpiSyncService, CoachingKpiSyncService,
                          #   TeamAggregationKpiSyncService
  app/Http/Controllers/Web/ # Controller halaman admin Inertia dan aksi workflow
  app/Support/             # Registry resource admin dan aturan akses
  resources/js/            # Halaman React/Inertia, layout, dan komponen UI
  routes/                 # web.php / api.php
mobile/                   # Flutter legacy, hanya referensi saat migrasi
  lib/app/                # app-level setup
  lib/core/               # api, auth (shared infra)
  lib/features/           # approval, auth, dashboard, imports, my_kpi,
                          # notifications, operational, profile, review
```

## Command yang sering dipakai

```bash
# Aplikasi aktif
cd web
docker compose up -d
npm run db:deploy
npm run db:seed
npm run dev
npm run check
```

## Konvensi

- **Aplikasi aktif:** fitur baru masuk ke `web/src/app/`; aturan bisnis yang dipakai lintas halaman masuk ke `web/src/modules/`.
- **Legacy:** `backend/` dan `mobile/` tidak lagi menjadi target pengembangan; gunakan hanya untuk verifikasi parity selama migrasi.
- **Git:** jangan commit `.env`, `.next/`, `node_modules/`, `vendor/`, `build/`, atau `.dart_tool/`.
- **Komunikasi:** bahasa Indonesia (produk & chat).
