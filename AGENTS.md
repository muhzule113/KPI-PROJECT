# AGENTS.md — KPI_PROJECT

Sistem Manajemen KPI untuk Toko & Servis HP. PRD lengkap: `PRD_Sistem_KPI_Toko_Servis_HP.md` (source of truth untuk aturan bisnis, formula KPI, dan role).

## Stack

| Bagian | Teknologi | Lokasi |
|---|---|---|
| Backend (Web admin) | Laravel + Inertia React | `backend/` |
| Database | MySQL (`kpi_management_db`) | — |
| Mobile | Flutter (`kpi_mobile`) | `mobile/` |
| Bahasa produk | Indonesia | — |

## Struktur

```
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
mobile/                   # Flutter — clean architecture
  lib/app/                # app-level setup
  lib/core/               # api, auth (shared infra)
  lib/features/           # approval, auth, dashboard, imports, my_kpi,
                          # notifications, operational, profile, review
```

## Command yang sering dipakai

```bash
# Backend
cd backend && php artisan serve          # jalankan dev server
php artisan migrate                      # migrasi DB
php artisan route:list --path=app       # cek route admin
npm run build                            # build React/Inertia

# Mobile
cd mobile && flutter run                 # jalankan app
flutter test                             # tes
```

## Konvensi

- **Mobile:** fitur baru masuk ke `lib/features/<nama>/`, jangan campur di `app/` atau `core/`. Infra bersama (API client, auth) di `lib/core/`.
- **Backend:** logika bisnis per domain di `app/Modules/<Domain>/`, jangan numpuk di controller. Admin UI lewat Inertia React di `/app`.
- **Git:** jangan commit `vendor/`, `.env`, `build/`, `.dart_tool/` (sudah di-cover .gitignore masing-masing).
- **Komunikasi:** bahasa Indonesia (produk & chat).
