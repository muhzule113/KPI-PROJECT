# KPI OPS Web

Aplikasi utama Sistem Manajemen KPI Toko dan Servis HP. Satu aplikasi Next.js melayani desktop dan ponsel secara responsif, menggantikan pemisahan Laravel dan Flutter.

## Stack

- Next.js 16 App Router dan TypeScript
- PostgreSQL 17 dan Prisma 7
- Tailwind CSS 4 dan komponen bergaya shadcn/ui
- Better Auth untuk login, sesi, logout, dan pemulihan kata sandi

Runtime aplikasi hanya memakai PostgreSQL. Paket `mysql2` dipakai khusus oleh perintah migrasi satu kali dari database Laravel lama.

## Menjalankan lokal

Prasyarat: Node.js 20.16 sampai sebelum 21, atau 22.3 ke atas, dan Docker Desktop.

```powershell
Copy-Item .env.example .env
# Ganti BETTER_AUTH_SECRET dan SEED_ADMIN_PASSWORD di .env.
docker compose up -d
npm install
npm run db:generate
npm run db:deploy
npm run db:seed
npm run dev
```

Buka `http://localhost:3000`. Akun awal mengikuti `SEED_ADMIN_EMAIL` dan `SEED_ADMIN_PASSWORD` di `.env`.

Untuk membuat secret lokal:

```powershell
npx auth@latest secret
```

SMTP bersifat opsional saat pengembangan. Tanpa `SMTP_HOST`, URL pemulihan kata sandi ditulis ke terminal server dan tidak dikirim melalui email.

File evidence selalu disimpan privat, diverifikasi berdasarkan ekstensi/MIME/signature, dipindai malware, dan diperiksa ulang melalui SHA-256 saat diunduh. Windows memakai Microsoft Defender secara bawaan; host Linux perlu menyediakan `clamdscan` atau mengisi `MALWARE_SCANNER_PATH`.

Jalankan pemeliharaan berikut setiap jam melalui Task Scheduler, cron, atau scheduler platform deployment. Perintah memakai PostgreSQL advisory lock dan kunci deduplikasi, sehingga aman jika pemanggilan bertumpuk atau diulang:

```powershell
npm run jobs:run
```

Job tersebut memproses antrean impor secara idempoten, menyinkronkan fakta KPI sampai tanggal Asia/Makassar saat ini, serta mengirim reminder H-3/H-1 untuk laporan dan review.

Jadwalkan backup PostgreSQL harian dan simpan hasilnya di media terpisah dari server aplikasi. `BACKUP_DIR` dan `PG_BIN` dapat diatur di `.env` bila lokasi bawaan tidak sesuai:

```powershell
npm run db:backup
npm run db:restore:verify
```

Perintah kedua membuat database sementara, memulihkan dump, membandingkan jumlah baris tabel inti, lalu menghapus database sementara. Jalankan drill ini berkala untuk membuktikan bahwa backup benar-benar dapat dipulihkan.

## Pemeriksaan

```powershell
npm run check
```

Perintah tersebut menjalankan lint, pemeriksaan tipe, test aturan perhitungan dan workflow, serta build produksi.

## Struktur penting

```text
src/app/                  halaman, route handler, dan server action
src/components/           komponen UI responsif
src/modules/access/       role, capability, dan pembatasan cakupan data
src/modules/kpi/          formula, workflow, dan kalkulasi persisten
src/modules/tickets/      workflow tiket servis
prisma/schema.prisma      skema PostgreSQL
prisma/seed.ts            cabang awal, jabatan, dan akun Super Admin
```

## Cakupan migrasi

Sudah tersedia autentikasi, dashboard berbasis role, KPI pribadi, kalkulasi, review Supervisor, persetujuan Manajer, koreksi dengan dual authorization, servis dan pembayaran, feedback pelanggan, stok dan opname, impor laporan kasir, notifikasi, laporan CSV/XLSX/PDF, pengaturan, serta tampilan ponsel dengan navigasi bawah.

Migrasi MySQL lama ke PostgreSQL tersedia dan idempotent:

```powershell
npm run db:migrate:legacy:dry
npm run db:migrate:legacy
npm run db:verify:legacy
```

Isi `LEGACY_DATABASE_URL` atau `LEGACY_ENV_FILE` terlebih dahulu. Migrator menyalin ID sumber, memulihkan relasi sirkular, membuat akun credential Better Auth dari hash Laravel, lalu membandingkan seluruh ID sumber dan tujuan. Folder `backend/` dan `mobile/` tetap disimpan hanya sebagai referensi verifikasi sampai cutover disetujui; keduanya bukan runtime aplikasi baru.
