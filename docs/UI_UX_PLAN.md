# 🎨 Planning: Perbaikan UI/UX Design Mobile KPI_PROJECT

> **Project:** KPI_PROJECT `mobile/` (Flutter, Material 3)
> **Skill yang dipakai:** ui-ux-pro-max, mobile-principles, design-audit, design-dna, cast, motion-principles
> **Status:** Planning (belum eksekusi)

---

## 1. Tujuan (Goal)

Meningkatkan kualitas UI/UX app mobile KPI dari "fungsional tapi polos" menjadi "profesional & premium" — dengan prinsip:

1. **Aksesibel** (WCAG AA: kontras ≥4.5:1, touch target ≥44-48dp, reduced-motion support)
2. **Konsisten** (design tokens dipakai di semua layar, 8dp spacing system)
3. **Hidup tapi profesional** (micro-interaction halus, bukan animasi lebay)
4. **Tanpa dependency baru** (Flutter native animation cukup — skill `cast` rule #4)

**Constraint:** 4 widget test harus tetap pass (`test/*.dart`), tidak merusak alur role-based.

---

## 2. Hasil Audit (dari sesi sebelumnya)

### Ringkasan kondisi
- Theme sudah bagus (Material 3, emerald `#10B981`, tokens di `AppTheme`)
- **Masalah utama:** kontras primary gagal WCAG (2.54:1), emoji sebagai ikon, font <12px, bottom nav bisa overflow >5 item, spacing tidak konsisten

### Temuan prioritas (dari skill ui-ux-pro-max)

| # | Issue | Severity | Lokasi |
|---|-------|----------|--------|
| 1 | Primary `#10B981` kontras 2.54:1 vs putih → **gagal WCAG AA** | 🔴 Critical | `app_theme.dart` |
| 2 | Emoji sebagai ikon struktural (⭐ ✅ ❌) | 🔴 High | `customer_pickup_screen`, `ticket_progress_screen` |
| 3 | Bottom nav >5 item (manager: 7 item) | 🟠 High | `dashboard_screen.dart` |
| 4 | Font <12px di 9 lokasi | 🟠 Medium | approval, dashboard, cashier, history |
| 5 | `fontFamily: 'Inter'` tidak ter-bundle → fallback tak konsisten | 🟡 Medium | `app_theme.dart` |
| 6 | Greeting emoji 👋 | 🟡 Low | `dashboard_screen.dart:168` |
| 7 | Icon-only buttons tanpa `Semantics`/tooltip konsisten | 🟡 Medium | semua screen |
| 8 | Spacing campur 16/18/20/24 tanpa sistem 8dp | 🟡 Medium | semua screen |
| 9 | Progress bar KPI polos (tanpa animasi/label) | 🟢 Nice | `dashboard_screen.dart:298` |
| 10 | Angka tidak tabular (skor/persen bergeser) | 🟢 Nice | dashboard, my_kpi |

---

## 3. Fase Eksekusi (diurutkan: dampak tinggi → rendah risiko)

### Fase 0 — Design System Refresh (dasar semua yang lain)
**File:** `app/theme/app_theme.dart`

| Item | Sekarang | Target | Alasan |
|------|----------|--------|--------|
| `primary` | `#10B981` (Emerald 500) | **`#047857` (Emerald 700)** | Kontras 5.48:1 → lolos WCAG AA untuk teks putih |
| `statusApproved` | `#10B981` | `#059669` (Emerald 600) | Beda dari primary, tetap hidup |
| `statusSubmitted` | `#3B82F6` | pertahankan | Blue ok |
| Tambah `primaryPressed` | — | `#065F46` (Emerald 800) | Depth konsisten |
| `fontFamily` | `'Inter'` (tidak ter-bundle) | Hapus → pakai default platform (Roboto/SF) | Konsisten antar device |
| Tambah spacing tokens | — | `space-4/8/12/16/24/32` | 8dp system |
| Tambah `textTheme` | default | Scale: 12/14/16/18/24/32 | Hierarki konsisten |

**Verifikasi:** `flutter analyze` bersih, widget test pass.

---

### Fase 1 — Quick Wins (High Impact, Low Risk)
**Layar:** dashboard, login, tiket list, my_kpi, profile

1. **Ganti emoji → ikon Material:**
   - `dashboard_screen.dart:168` — "Halo, 👋" → hapus emoji, ganti dengan ikon `Icons.waving_hand` sebagai `Icon` widget
   - `customer_pickup_screen.dart:153-157` — rating ⭐ → `Icons.star_rounded` / `star_outline_rounded` (sudah ada di atas, jadi tinggal konsisten)
   - `ticket_progress_screen.dart:578-579` — ✅/❌ dropdown → `Icons.check_circle` / `Icons.cancel` sebagai leading icon
2. **Fix font <12px** → naikin ke 12px minimum (9 lokasi)
3. **Tambah tooltip + Semantics** ke icon-only buttons (history, sync, notification, clear search)
4. **Dashboard KPI progress bar:** tambah label persentase di dalam bar + `AnimatedContainer` (durasi 250ms, ease-out) buat smooth transition

---

### Fase 2 — Navigation Refresh (Bottom Nav)
**File:** `dashboard_screen.dart`

**Masalah:** manager/supervisor punya 7 item bottom nav — melebihi max 5 (skill rule `bottom-nav-limit`).

**Solusi (2 opsi):**
- **Opsi A (disarankan):** Bottom nav tetap ≤5 item utama (Beranda, Tiket/Inventory, KPI Saya, Review/Approval, Profil). Item sekunder (Laporan Kasir, dll) pindah ke **overflow menu / drawer**.
- **Opsi B:** Konversi ke `NavigationBar` Material 3 dengan `NavigationDrawer` untuk item sekunder.

**Keputusan:** pilih A atau B — gw rekomendasikan A karena perubahan minimal & familiar.

---

### Fase 3 — Polishing Layar Utama

**Dashboard (Beranda):**
- Greeting header: tambah avatar circle + nama, rapiin spacing
- Active Period Card: pertahankan gradient (sudah bagus), tambah icon kalender + progress
- Metric cards (`_metricCard`): rapikan jadi 3 kolom konsisten, angka tabular

**My KPI:**
- Status header: pisah jadi 2 baris (period + status chip), kurangi ramai
- Info banner: tetap, rapikan icon
- Indicator card: tambah progress bar kecil per indikator (visualisasi pencapaian vs target)

**Login:**
- Brand header: perbesar icon container, tambah subtle gradient
- Demo chips: rapikan, beri label "Mode Demo" badge

---

### Fase 4 — Design DNA (opsional, kalau mau referensi visual)
Pakai skill `design-dna`: ambil screenshot app sekarang → generate Design DNA JSON → bandingkan dengan target premium → refine. Ini jalan kalau mau hasil yang "reference-faithful".

---

## 4. Checklist Verifikasi (skill design-audit)

Sebelum deliver, cek:
- [ ] `flutter analyze` — No issues found
- [ ] `flutter test` — semua 4 widget test pass
- [ ] Kontras primary ≥4.5:1 (hitung ulang)
- [ ] Tidak ada emoji sebagai ikon struktural
- [ ] Semua touch target ≥44dp (IconButton pakai `constraints`/padding)
- [ ] Font minimum 12px
- [ ] Bottom nav ≤5 item
- [ ] Semua icon-only button punya tooltip/`Semantics`
- [ ] Spacing 8dp konsisten
- [ ] Reduced-motion: animasi pakai durasi ≤300ms, gak ada yang mengganggu

---

## 5. Risiko & Mitigasi

| Risiko | Mitigasi |
|--------|----------|
| Widget test rusak | Jaga struktur widget (jangan rename class/finder), cek test dulu sebelum ubah |
| Kontras primary terlalu gelap | Test visual di device; kalau terlalu gelap, kompromi di `#059669` (3.77:1, lolos large text) |
| Bottom nav refactor mutus flow | Uji manual semua role (teknisi/CS/gudang/kasir/manager/supervisor) |
| Font platform fallback beda | Ini justru yang bener — platform-native lebih konsisten |

---

## 6. Next Steps

1. ✅ (selesai) Audit & planning
2. ✅ (selesai) **Fase 0** — Design system refresh (theme: primary WCAG, spacing, text scale, pressed state, hapus font Inter)
3. ✅ (selesai) **Fase 1** — Quick wins (emoji→ikon, font<12px fix, tooltip/Semantics, progress bar animasi)
4. ✅ (selesai) **Fase 2** — Navigation (bottom nav ≤5, Review/Approval/Laporan Kasir → overflow menu)
5. ✅ (selesai) **Fase 3** — Polishing (dashboard avatar+progress, my_kpi progress per indikator, login brand header)
6. ✅ (selesai) Verifikasi final (flutter analyze bersih + 5 widget test pass)

### Hasil eksekusi (commit [TBD])
- **Primary:** `#10B981` → `#047857` (Emerald 700, kontras 5.48:1 WCAG AA)
- **Status approved:** → `#059669` (Emerald 600, beda dari primary)
- **Emoji → ikon Material:** greeting avatar (bukan 👋), rating bintang, dropdown hasil (✅/❌/⚠️ → icon)
- **Font min 12px** di 11 file
- **Tooltip/Semantics** di 5 icon-only buttons
- **Progress bar animasi** (dashboard + per indikator my_kpi) — AnimatedContainer 250ms ease-out, respect reduced-motion
- **Bottom nav ≤5 item**, overflow menu utk Review/Approval/Laporan Kasir
- **Login:** brand header gradient + badge "Mode Demo"

### Belum dikerjakan (backlog)
- [ ] Fase 4 — Design DNA (screenshot → DNA JSON → refine ke referensi premium) — opsional, kalau mau
- [ ] Uji manual visual di device (perlu emulator/device)

---

*Dokumen ini bisa dipakai sebagai tracking. Update status tiap fase selesai.*
