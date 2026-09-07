# Bukti Audit Playwright Seluruh Akun

Tanggal: 7 September 2026  
Target: `http://127.0.0.1:8000`  
Source plan: tidak ada; journey diturunkan dari PRD, `AdminNavigation`, dan `CapabilityMatrix`.

## User journey

1. Sebagai pengguna web, saya dapat login, hanya melihat menu sesuai role/jabatan, membuka seluruh halaman dan form GET yang tersedia, lalu logout.
2. Sebagai pengguna dengan akses terbatas, saya mendapat HTTP 403 ketika membuka resource terlarang secara langsung.
3. Sebagai pengguna mobile-only, saya ditolak dari website dengan pesan platform yang tepat.

## Hasil

Playwright menguji 10 akun seed, 143 kunjungan pola halaman lintas akun, login/logout, jumlah menu, error JavaScript, respons 5xx, dan satu route terlarang per role terbatas.

| Akun | Platform yang diuji | Menu | Pola halaman | Hasil |
|---|---:|---:|---:|---|
| `admin@kpi.com` | web | 31 | 63 | PASS |
| `kpi_admin@kpi.com` | web | 17 | 31 | PASS |
| `auditor@kpi.com` | web | 5 | 5 | PASS |
| `manager@toko.com` | web | 9 | 15 | PASS |
| `supervisor@toko.com` | web | 11 | 15 | PASS |
| `teknisi@toko.com` | mobile-only gate | — | 1 | PASS |
| `cs@toko.com` | mobile-only gate | — | 1 | PASS |
| `admin_staff@toko.com` | web | 3 | 4 | PASS |
| `kasir@toko.com` | web | 4 | 7 | PASS |
| `gudang@toko.com` | mobile-only gate | — | 1 | PASS |

Ringkasan akhir Playwright: **10 akun PASS, 0 akun FAIL**. Tidak ditemukan respons server 5xx, page error, CSP block, atau kegagalan WebSocket.

## Temuan

### RED-1 — KPI Saya gagal dirender

- Reproducer: Supervisor, Admin Staff, dan Kasir membuka `/app/my-kpi/daily`.
- Aktual: browser memunculkan `ReferenceError: Input is not defined`; halaman tidak mempunyai heading.
- Root cause: `resources/js/Pages/Employee/DailyKpi.jsx` memakai `<Input>` tetapi tidak mengimpor komponen `Input`.
- Perbaikan: impor `Input` ditambahkan pada `DailyKpi.jsx`.
- Status: GREEN; Supervisor, Admin Staff, dan Kasir dapat membuka KPI Saya tanpa page error.

### RED-2 — koneksi realtime lokal tidak aktif

- Semua akun mencatat CSP memblokir `ws://localhost:8080`.
- Klien lalu mencoba `wss://localhost:8080` dan mendapat `ERR_CONNECTION_REFUSED` pada halaman terautentikasi.
- Konfigurasi terkait: `resources/js/echo.js` mengaktifkan transport `ws`/`wss`, sementara CSP `app/Http/Middleware/SecurityHeaders.php` hanya mengizinkan `https:`/`wss:` dan proses Reverb tidak tersedia pada port 8080 saat audit.
- Perbaikan: CSP mengizinkan `ws:` hanya pada environment `local`; production tetap hanya mengizinkan transport aman. Reverb dijalankan pada port 8080.
- Status: GREEN; koneksi Playwright tidak lagi menghasilkan CSP block atau `ERR_CONNECTION_REFUSED`.

## Spesifikasi bukti

| # | Jaminan yang diuji | Target | Jenis | Hasil |
|---|---|---|---|---|
| 1 | Seluruh 10 akun dapat diautentikasi atau ditolak sesuai platform | `tests/Browser/realtime-role-audit.mjs` | E2E | PASS |
| 2 | Menu web sesuai matriks capability per akun | test yang sama | E2E | PASS |
| 3 | Halaman/menu dan form GET yang ditemukan tidak menghasilkan 4xx/5xx tak terduga | test yang sama | E2E | PASS |
| 4 | Route terlarang mengembalikan HTTP 403 | test yang sama | E2E/security | PASS |
| 5 | KPI Saya dapat dirender tanpa exception browser | test yang sama | E2E | PASS |
| 6 | Backend workflow dan otorisasi existing tetap hijau | `php artisan test --compact` | unit/integration | PASS — 184 test, 1.166 assertion |
| 7 | Widget dan navigasi mobile existing tetap hijau | `flutter test` | widget/unit | PASS — 19 test |
| 8 | Frontend production dapat dibundel | `npm run build` | build | PASS — 3.001 modul |

## Perintah dan evidence

```text
node tests/Browser/realtime-role-audit.mjs
accounts: 10; passed: 10; failed: 0
Tidak ada page error, server error, CSP block, atau kegagalan WebSocket.

php artisan test --compact
Tests: 184 passed (1166 assertions)

flutter test
All tests passed! (19 tests)

npm run build
3001 modules transformed; built in 1.21s

php artisan test --coverage --min=80
ERROR Code coverage driver not available. Did you install Xdebug or PCOV?
```

## Coverage dan gap

- Persentase coverage 80% belum dapat dibuktikan karena environment tidak memiliki Xdebug/PCOV.
- Audit Playwright sengaja read-only terhadap database live: halaman create/edit dan kontrol akses dibuka, tetapi submit mutasi, upload, delete, approval, pembayaran, dan finalisasi tidak dijalankan agar data live tidak berubah. Jalur tersebut tetap dicakup oleh suite Laravel existing, bukan oleh Playwright.
- Tiga akun operasional mobile-only diuji pada web hanya untuk platform gate; UI native Flutter tidak dapat dijalankan oleh Playwright.

## Merge evidence

- RED (`1b42efd`): Playwright gagal pada KPI Saya untuk tiga akun dan koneksi realtime; test CSP lokal juga gagal.
- GREEN (`8e7f87c`): seluruh 10 akun, matriks menu, route denial, KPI Saya, realtime, 184 test backend, 19 test Flutter, dan build frontend lulus.
- Refactor: tidak ada.
