# KPI Harian Sederhana

Aplikasi web responsif untuk penilaian KPI manual harian. Supervisor menilai staf, Manager menilai Supervisor dan wajib mereview staf, lalu hasil terakumulasi dan difinalkan per pegawai setelah akhir bulan.

## Stack dan port

- Next.js + TypeScript + Tailwind CSS + komponen shadcn/ui
- Better Auth untuk login dan sesi
- PostgreSQL 17 pada `127.0.0.1:5435`
- Prisma ORM dan migrasi pada `prisma/`
- Aplikasi pada `http://localhost:3002`

Database, container, dependensi, dan storage folder ini terpisah dari `web/`, `backend/`, dan `mobile/`. Tidak ada migrasi data legacy otomatis.

## Menjalankan lokal

```powershell
cd web-simple
Copy-Item .env.example .env
# Isi secret dan password lokal di .env
npm ci
docker compose up -d
npm run db:deploy
npm run db:seed
npm run dev
```

Buka `http://localhost:3002` persis seperti nilai `APP_URL`. Jika port atau domain berubah, ubah `APP_URL` dan `BETTER_AUTH_URL` bersama-sama lalu restart aplikasi.

## Akun demo

Semua akun memakai nilai `SEED_DEMO_PASSWORD` dari `.env`:

| Role | Username |
|---|---|
| Super Admin utama | `admin` |
| Super Admin cadangan | `admin.cadangan` |
| Manager | `manager` |
| Supervisor | `supervisor` |
| Pegawai/Pelayan | `pegawai` |
| Pegawai/Teknisi | `teknisi` |

Ubah kata sandi kedua Super Admin dan seluruh akun demo sebelum memakai sistem dengan data nyata. Pengguna yang lupa kata sandi meminta Super Admin meresetnya dari menu Pengguna; sistem mempertahankan minimal dua Super Admin aktif.

## Alur kerja

1. Super Admin memakai menu Cabang, Jabatan, Pengguna, Indikator KPI, Predikat Nilai, dan Periode untuk menyiapkan master penilaian.
2. Jabatan KPI baru otomatis memperoleh template v1 berstatus DRAFT. Hanya draft yang dapat diedit dan total bobot wajib tepat 100% sebelum diaktifkan.
3. Super Admin membuat periode DRAFT lalu membukanya. Sistem menyimpan snapshot versi indikator dan predikat aktif, kemudian membuat satu lembar untuk setiap pegawai dan setiap tanggal dalam masa kerja.
4. Supervisor mengisi status kerja dan nilai aktual staf. Manager mengisi KPI Supervisor langsung.
5. Manager menyetujui, mengoreksi dengan alasan, atau mengembalikan lembar staf.
6. Hanya hari `Bekerja` yang disetujui masuk ke SUM/AVERAGE bulanan. Nilai kosong tidak dianggap nol.
7. Pegawai dapat melihat status dan nilai harian yang sudah dikirim, serta skor/predikat sementara dari hari yang sudah disetujui. Setelah akhir periode dan seluruh hari disetujui, Manager memfinalkan hasil per pegawai sebagai hasil resmi.
8. Super Admin dapat membuka hasil final kembali dengan alasan; perubahan tersimpan pada audit.

Evidence bersifat opsional, maksimal 3 file per lembar dan 10 MB per file. File disimpan privat, diverifikasi tipe/hash, dan wajib lolos Windows Defender atau scanner yang ditentukan lewat `MALWARE_SCANNER_PATH`.

Enam jabatan utama memakai katalog baku 39 indikator dari referensi WhatsApp. Instalasi baru mendapat katalog tersebut dari seed. Untuk database yang sudah berjalan, buat backup lalu jalankan `npm run db:sync:kpi`; perintah ini idempoten dan hanya mengubah versi aktif untuk periode berikutnya. Draft target yang telah diedit pengguna akan menghentikan seluruh transaksi.

## Pemeriksaan

```powershell
npm run check
npm run verify:auth
npm run verify:migration
npm run verify:workflow
npm run verify:configuration
npm run verify:seed
npm run verify:kpi
npm run verify:august
npm audit
```

`verify:workflow` menjalankan alur Supervisor → Manager → koreksi → agregasi terhadap PostgreSQL dan melakukan rollback seluruh data uji.

`verify:kpi` menguji sinkronisasi dua kali, konflik draft, serta fingerprint histori dan template di luar katalog, lalu melakukan rollback seluruh data uji.

## Catatan produksi

- Gunakan secret acak minimal 32 karakter dan kredensial PostgreSQL khusus.
- Backup database sebelum deploy migrasi username, jalankan seed idempoten untuk memastikan akun `admin.cadangan` tersedia, lalu ganti kedua kata sandi Admin.
- Sebelum `npm run db:sync:kpi`, backup database dan simpan fingerprint snapshot periode. Jalankan perintah dua kali untuk memastikan eksekusi kedua melewati keenam template tanpa membuat versi tambahan.
- Pasang storage `storage/evidence/` pada volume privat yang persisten dan ikut backup.
- Jalankan satu instance aplikasi untuk rate limit in-memory. Gunakan penyimpanan rate limit bersama jika nanti menjalankan beberapa instance.
- Aplikasi ini web responsif, bukan PWA dan tidak mempunyai mode offline.

## Deploy VPS 2 GB

Docker produksi menjalankan satu instance aplikasi dan PostgreSQL dengan batas total 1.152 MB. Build Next.js dibatasi 1 GB; sediakan swap 2 GB bila image dibangun langsung di VPS.

```bash
cd web-simple
cp .env.production.example .env.production
chmod 600 .env.production
# Isi domain, secret acak, dan password database.
docker compose --env-file .env.production -f compose.prod.yaml up -d --build
docker compose --env-file .env.production -f compose.prod.yaml --profile seed run --rm seed
curl --fail http://127.0.0.1:3002/api/health
```

Pasang Caddy atau Nginx di host untuk HTTPS dan arahkan domain ke `127.0.0.1:3002`. `APP_URL` dan `BETTER_AUTH_URL` wajib sama dengan URL HTTPS publik. Jika password PostgreSQL mengandung karakter khusus, gunakan bentuk URL-encoded pada `DATABASE_URL`.

Migrasi berjalan otomatis sebelum aplikasi dimulai. Perintah seed hanya perlu sekali untuk instalasi baru dan membuat akun demo; segera ganti password akun sebelum dipakai. Upload evidence tetap ditolak sampai `MALWARE_SCANNER_PATH` menunjuk scanner malware yang dapat dijalankan di container.

Untuk pembaruan berikutnya:

```bash
git pull --ff-only
docker compose --env-file .env.production -f compose.prod.yaml up -d --build
docker compose --env-file .env.production -f compose.prod.yaml ps
```
