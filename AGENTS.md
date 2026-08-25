# AGENTS.md — KPI_PROJECT

Sistem Manajemen KPI untuk Toko & Servis HP. PRD lengkap: `PRD_Sistem_KPI_Toko_Servis_HP.md` (source of truth untuk aturan bisnis, formula KPI, dan role).

## Stack

| Bagian | Teknologi | Lokasi |
|---|---|---|
| Backend (Web admin) | Laravel + **Filament 3.2** | `backend/` |
| Database | MySQL (`kpi_management_db`) | — |
| Mobile | Flutter (`kpi_mobile`) | `mobile/` |
| Bahasa produk | Indonesia | — |

## Struktur

```
backend/                  # Laravel + Filament
  app/Models/             # Eloquent models
  app/Modules/            # Approval, Assessment, Calculation, Import, Period, Review
  app/Modules/Assessment/ # Sync services tiap subsistem KPI:
                          #   OperationalKpiSyncService (composite — tiket + absensi +
                          #   inventory + admin work-log + komplain + coaching + agregasi tim),
                          #   AttendanceKpiSyncService, InventoryKpiSyncService,
                          #   StockOpnameService, AdminWorkLogKpiSyncService,
                          #   ComplaintKpiSyncService, CoachingKpiSyncService,
                          #   TeamAggregationKpiSyncService
  app/Filament/           # Resources & Widgets (admin panel); group 'Operasional Harian':
                          #   Absensi, Stock Opname, Work-Log Admin, Komplain, Coaching
                          #   Akses menu per role via App\Support\MenuAccess (Spatie role + position_code)
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
php artisan make:filament-resource X     # bikin resource admin

# Mobile
cd mobile && flutter run                 # jalankan app
flutter test                             # tes
```

## Konvensi

- **Mobile:** fitur baru masuk ke `lib/features/<nama>/`, jangan campur di `app/` atau `core/`. Infra bersama (API client, auth) di `lib/core/`.
- **Backend:** logika bisnis per domain di `app/Modules/<Domain>/`, jangan numpuk di controller. Admin panel lewat Filament Resources.
- **Git:** jangan commit `vendor/`, `.env`, `build/`, `.dart_tool/` (sudah di-cover .gitignore masing-masing).
- **Komunikasi:** bahasa Indonesia (produk & chat).
