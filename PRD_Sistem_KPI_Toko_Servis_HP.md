# Product Requirements Document (PRD)
# Sistem Manajemen KPI — Toko & Servis HP

| Metadata | Nilai |
|---|---|
| Versi | 1.1 |
| Status | Workflow dan pembagian akses disepakati |
| Tanggal | 7 September 2026 |
| Platform | Web (Laravel + Inertia React) + Mobile (Flutter) |
| Database | MySQL |
| Periode evaluasi | Bulanan (dengan input dan review harian) |
| Bahasa produk | Indonesia |

---

## Daftar Isi

1. [Latar Belakang & Masalah](#1-latar-belakang--masalah)
2. [Tujuan Produk](#2-tujuan-produk)
3. [Pengguna & Persona](#3-pengguna--persona)
4. [Ruang Lingkup](#4-ruang-lingkup)
5. [Struktur Organisasi & Role](#5-struktur-organisasi--role)
6. [Master KPI per Jabatan](#6-master-kpi-per-jabatan)
7. [Alur Sistem End-to-End](#7-alur-sistem-end-to-end)
8. [Alur per Role](#8-alur-per-role)
9. [Formula & Kalkulasi KPI](#9-formula--kalkulasi-kpi)
10. [Spesifikasi Fitur per Modul](#10-spesifikasi-fitur-per-modul)
11. [Import Laporan Kasir](#11-import-laporan-kasir)
12. [Dashboard & Laporan](#12-dashboard--laporan)
13. [Notifikasi](#13-notifikasi)
14. [Arsitektur Sistem](#14-arsitektur-sistem)
15. [Model Data & ERD](#15-model-data--erd)
16. [Keamanan & Anti-Manipulasi](#16-keamanan--anti-manipulasi)
17. [UI/UX & Design System](#17-uiux--design-system)
18. [Kebutuhan Non-Fungsional](#18-kebutuhan-non-fungsional)
19. [Roadmap Implementasi](#19-roadmap-implementasi)
20. [Acceptance Criteria](#20-acceptance-criteria)
21. [Keputusan Bisnis yang Wajib Dikunci](#21-keputusan-bisnis-yang-wajib-dikunci)

---

## 1. Latar Belakang & Masalah

### 1.1 Konteks Bisnis

Perusahaan bergerak di bidang penjualan dan servis HP. Struktur operasional melibatkan berbagai jabatan: Teknisi, Pelayan, Admin, Kasir, Gudang/Sparepart, dan Supervisor yang semuanya memiliki kontribusi berbeda terhadap performa bisnis.

### 1.2 Masalah yang Ada Saat Ini

Tanpa sistem KPI yang terstruktur, perusahaan menghadapi beberapa masalah:

- Penilaian kinerja dilakukan manual sehingga rawan kesalahan hitung dan inkonsistensi.
- Tidak ada standar objektif — penilaian bergantung pada persepsi atasan.
- Karyawan tidak tahu target mereka secara jelas sebelum periode berjalan.
- Tidak ada histori kinerja yang dapat ditelusuri untuk keperluan coaching dan pengembangan.
- Data dari sistem kasir (transaksi, selisih kas) tidak terintegrasi dengan penilaian karyawan.
- Proses review dan approval tidak terstruktur sehingga lambat dan tidak terdokumentasi.

### 1.3 Solusi yang Dibangun

Sistem KPI Management yang:
- Mendefinisikan indikator, bobot, dan target per jabatan secara transparan.
- Memisahkan siapa yang input data, siapa yang verifikasi, dan siapa yang approve.
- Mengintegrasikan laporan dari aplikasi kasir sebagai sumber data otomatis.
- Menghitung skor secara otomatis berdasarkan fakta, bukan klaim subjektif.
- Menyediakan dashboard real-time untuk semua level manajemen.
- Menyimpan histori lengkap yang dapat diaudit.

---

## 2. Tujuan Produk

### 2.1 Tujuan Bisnis

- Menyatukan definisi KPI dan bobot per jabatan di seluruh toko.
- Mengurangi kesalahan dan kecurangan dalam penilaian kinerja.
- Menyediakan histori kinerja karyawan, tim, dan toko yang dapat ditelusuri.
- Membuat proses review, approval, dan koreksi dapat dilacak dan dipertanggungjawabkan.
- Menyediakan dasar yang objektif untuk coaching, penghargaan, dan pengembangan karyawan.

### 2.2 Tujuan Produk

- Karyawan memahami target mereka dan mengisi fakta pekerjaan — bukan menebak skor.
- Supervisor memiliki antrean review yang jelas dan terstruktur.
- Manager/Owner memantau performa sesuai snapshot cabang dan assignment dari satu dashboard.
- Sistem menghasilkan skor yang reproducible — dapat direproduksi ulang dengan data yang sama.
- Data dari aplikasi kasir pihak ketiga dapat dipakai tanpa membangun ulang sistem transaksi.

### 2.3 Metrik Keberhasilan

- 100% karyawan aktif memiliki KPI yang di-generate setiap periode.
- Tingkat penyelesaian submission > 95% sebelum deadline.
- Waktu review Supervisor < 3 hari kerja per karyawan.
- Tidak ada skor yang dapat diubah tanpa audit trail yang lengkap.
- Selisih perhitungan manual vs sistem = 0%.

---

## 3. Pengguna & Persona

### 3.1 Owner / Manager

**Profil:** Pemilik atau pengelola toko. Melihat gambaran besar performa seluruh toko dan tim.

**Kebutuhan:**
- Dashboard ringkasan performa seluruh jabatan dan periode.
- Perbandingan antar karyawan, antar periode (trend).
- Memantau hasil staf yang dinilai Supervisor dan mengerjakan penilaian KPI Supervisor.
- Final approval KPI sebelum hasil diumumkan.
- Laporan yang bisa diekspor untuk evaluasi bisnis.

**Frustrasi saat ini:** Tidak punya data objektif untuk keputusan kenaikan gaji atau bonus. Bergantung pada laporan verbal Supervisor.

### 3.2 Supervisor

**Profil:** Mengelola tim operasional (Teknisi, Pelayan, Admin, Kasir, Gudang). Bertanggung jawab atas performa tim.

**Kebutuhan:**
- Melihat progres penilaian anggota tim.
- Review fakta dan evidence dari pekerjaan operasional.
- Mengisi penilaian subjektif (SOP, kerapian) via checklist.
- Menyetujui atau meminta revisi penilaian harian anggota tim.
- Meminta revisi jika data tidak sesuai.
- Coaching action plan untuk karyawan di bawah target.

**Frustrasi saat ini:** Harus review secara manual dan hasilnya tidak tersimpan dengan baik.

### 3.3 Karyawan Operasional (Teknisi, Pelayan, Admin, Kasir, Gudang)

**Profil:** Karyawan lapangan yang mengerjakan tugas harian.

**Kebutuhan:**
- Tahu target KPI mereka sebelum periode mulai.
- Bisa input data pekerjaan secara bertahap (draft).
- Tahu status review KPI mereka.
- Melihat fakta dan histori revisi; hasil penilaian menunggu publikasi.
- Lihat hasil akhir dan feedback Supervisor.

**Frustrasi saat ini:** Tidak tahu dinilai dari apa. Sering kaget dengan hasil penilaian akhir.

### 3.4 System Admin / KPI Admin

**Profil:** Staff IT atau admin yang mengelola konfigurasi sistem.

**Kebutuhan:**
- Kelola master data (karyawan, jabatan, template KPI).
- Buka dan tutup periode penilaian.
- Kelola mapping import laporan kasir.
- Audit log dan troubleshooting.

---

## 4. Ruang Lingkup

### 4.1 In Scope (MVP)

- Autentikasi: login, logout, reset password, manajemen sesi.
- Struktur organisasi: karyawan, jabatan, departemen, tim, Supervisor, Manager.
- Role dan permission berbasis RBAC.
- Master KPI: definisi indikator, template per jabatan, versi template, bobot, target, formula, rubric.
- Periode KPI bulanan dengan deadline; input fakta dan review berlangsung per hari, lalu direkap ke hasil bulanan.
- Alur karyawan: pekerjaan operasional, fakta otomatis, status/evidence dan hasil setelah publikasi.
- Alur Supervisor: review, verifikasi, checklist subjektif, request revisi.
- Alur Manager: tinjauan harian opsional staf, approval/return rekap staf, dan penilaian langsung KPI Supervisor.
- KPI Engine: kalkulasi otomatis, achievement, weighted score, rating/predikat.
- Import laporan kasir: XLSX, CSV, dan PDF text-based.
- Dashboard role-based: Manager, Supervisor, Karyawan.
- Ranking, trend, rekap, dan export laporan.
- Notifikasi in-app dan push notification.
- Audit trail dan histori perubahan.
- Web app (Laravel + Inertia React) dan mobile app (Flutter, Android & iOS).

### 4.2 Out of Scope (MVP)

- Sistem POS/kasir, CRM, inventory, atau absensi penuh.
- Integrasi API real-time dengan aplikasi pihak ketiga.
- Payroll, bonus otomatis, atau sanksi otomatis.
- Penilaian 360 derajat.
- Forecast berbasis machine learning.
- Multi-perusahaan (multi-tenant).
- Offline submit/approval — draft lokal boleh, submit butuh koneksi.

---

## 5. Struktur Organisasi & Role

### 5.1 Hierarki Organisasi

```
Owner / Manager
└── Supervisor
    ├── Teknisi
    ├── Pelayan
    ├── Admin
    ├── Kasir
    └── Gudang / Sparepart
```

### 5.2 Role dan platform aplikasi

| Pengguna | Platform | Tanggung jawab |
|---|---|---|
| Teknisi (`employee`, `POS-TEK`) | Mobile | Ambil tiket, diagnosis, progres, sparepart, QC, KPI sendiri |
| Pelayan (`employee`, `POS-CS`) | Mobile | Penerimaan, persetujuan pelanggan, penyerahan, feedback, komplain |
| Gudang (`employee`, `POS-GUD`) | Mobile | Sparepart, stok, opname, KPI sendiri |
| Kasir (`employee`, `POS-KSR`) | Mobile dan web | Biaya, pembayaran, laporan kasir, KPI sendiri |
| Admin Operasional (`employee`, `POS-ADM`) | Mobile dan web | Work-log, dokumen, rekonsiliasi, KPI sendiri |
| Supervisor (`supervisor`) | Mobile dan web | Absensi dan penilaian harian tim, rekap, coaching, verifikasi sumber |
| Manager/Owner (`owner_manager`) | Mobile dan web | Approval staf, penilaian dan finalisasi KPI Supervisor, laporan sesuai assignment |
| Admin KPI (`kpi_admin`) | Web | Periode, assignment penilai, mapping import, monitoring, publikasi |
| Super Admin (`super_admin`) | Web | Skala predikat, indikator, template, target, bobot, rubrik, serta seluruh tindakan web lintas role dan cabang |
| Auditor (`auditor`) | Web | Laporan, histori, dan audit lintas cabang; hanya baca |

Jabatan menentukan pekerjaan dan template; role menentukan kewenangan aplikasi. Kombinasi administratif/auditor dengan role operasional ditolak. Super Admin menjadi pengecualian yang memiliki seluruh kewenangan web tanpa akses mobile; Admin KPI dan Auditor tidak memperoleh hak transaksi atau penilaian. CapabilityMatrix menjadi kontrak bersama untuk login, sesi, menu, resource, dan controller. Sesi lama diperiksa ulang pada setiap request; API mobile menggunakan token yang diterbitkan server untuk kanal mobile. Akun nonaktif dan profil operasional tidak lengkap ditolak.

### 5.3 Prinsip Pemisahan Tugas

- Fakta objektif berasal dari modul operasional atau import dan tidak dapat diubah Supervisor; Supervisor mengonfirmasi atau meminta koreksi sumber. KPI subjektif dinilai Supervisor dengan satu predikat per hari.
- Rekap staf tidak menunggu penilaian harian Manager. Bila Manager belum bertindak, hasil Supervisor tetap menjadi nilai efektif.
- Setelah Supervisor menyetujui penilaian staf, Manager yang ditugaskan dapat mengonfirmasi seluruh indikator per karyawan atau mengubah satu indikator. Perubahan wajib memiliki alasan, tidak mengubah fakta sumber, dan menjadi nilai efektif harian.
- Khusus KPI Supervisor, Manager yang ditugaskan menilai dan memfinalisasi langsung. Satu Manager cukup; self-assessment dan self-approval tetap dilarang.
- KPI Supervisor menunggu hasil anggota tim; approval anggota tidak menunggu KPI Supervisor.
- Skor dan predikat KPI sendiri hanya terlihat setelah publikasi periode. Penilai yang ditugaskan dapat melihat hasil tim untuk tugas review/approval; Auditor membaca lintas cabang dan Admin KPI memonitor periode.

---

## 6. Master KPI per Jabatan

### 6.1 Teknisi

| Kode | Indikator | Bobot | Target | Arah | Sumber Data | Yang Isi |
|---|---|---:|---|---|---|---|
| TEK-01 | Jumlah servis selesai | 25% | ≥ [N] unit/bulan | Higher | Tiket servis | Sistem otomatis |
| TEK-02 | Tingkat keberhasilan servis | 25% | ≥ 95% | Higher | Tiket servis | Sistem otomatis |
| TEK-03 | Tingkat retur/komplain | 15% | ≤ 3% | Lower | Record retur dari Pelayan/Admin | Cross-role (Pelayan/Admin) |
| TEK-04 | Ketepatan waktu pengerjaan | 15% | ≥ 95% | Higher | Timestamp mulai & selesai | Sistem otomatis |
| TEK-05 | Kepatuhan SOP | 10% | ≥ 95% | Higher | Checklist observasi | Supervisor |
| TEK-06 | Kerapian & kebersihan | 5% | ≥ 90% | Higher | Checklist observasi | Supervisor |
| TEK-07 | Kelengkapan laporan servis | 5% | 100% | Higher | Field laporan di sistem | Sistem otomatis |
| | **Total** | **100%** | | | | |

**Field yang BOLEH diisi Teknisi:**
`jumlah_servis`, `status_servis`, `waktu_mulai`, `waktu_selesai`, `hasil_servis`, `diagnosa`, `tindakan`, `sparepart_dipakai`, `laporan_servis`, `evidence`, `catatan`

**Field yang TIDAK BOLEH diisi Teknisi:**
`jumlah_retur`, `skor_sop`, `skor_kerapian`, `achievement`, `weighted_score`, `final_score`, `rating`

### 6.2 Pelayan

| Kode | Indikator | Bobot | Target | Arah | Sumber Data | Yang Isi |
|---|---|---:|---|---|---|---|
| CS-01 | Kepuasan pelanggan | 25% | ≥ 90% | Higher | Rating Pelayan pada feedback customer | Sistem otomatis |
| CS-02 | Kecepatan melayani | 20% | ≥ 95% sesuai standar | Higher | Observasi dengan panduan rubrik | Supervisor memilih predikat |
| CS-03 | Akurasi input order | 20% | ≥ 98% | Higher | Order valid vs error | Sistem otomatis |
| CS-04 | Follow-up pelanggan | 15% | ≥ 95% | Higher | Record follow-up | Sistem otomatis |
| CS-05 | Jumlah komplain | 10% | ≤ [N] komplain/bulan | Lower | Record komplain kanal resmi | Cross-role / sistem |
| CS-06 | Kehadiran & disiplin | 10% | ≥ 95% | Higher | Data absensi | Sistem / Supervisor |
| | **Total** | **100%** | | | | |

> Pelayan juga berperan sebagai **sumber data retur Teknisi** — Pelayan mencatat retur dari pelanggan, sistem mengaitkannya ke Teknisi yang mengerjakan servis tersebut.

> CS-01 hanya memakai rating Pelayan pada feedback customer. Rating Teknisi disimpan untuk tindak lanjut layanan, tetapi tidak masuk perhitungan KPI CS-01.

> **Catatan terminologi:** Pelayan adalah nama bisnis baru untuk jabatan yang sebelumnya disebut CS. Kode posisi `POS-CS`, kode KPI `CS-*`, dan nama kolom database terkait dipertahankan agar data historis dan aturan akses tetap kompatibel.

### 6.3 Admin

| Kode | Indikator | Bobot | Target | Arah | Sumber Data | Yang Isi |
|---|---|---:|---|---|---|---|
| ADM-01 | Akurasi input data | 30% | ≥ 98% | Higher | Total record vs koreksi/error | Sistem otomatis dari work-log |
| ADM-02 | Ketepatan laporan | 25% | 100% tepat waktu | Higher | Timestamp submit vs deadline | Sistem otomatis |
| ADM-03 | Kelengkapan dokumen | 15% | ≥ 98% | Higher | Dokumen lengkap vs total eligible | Sistem otomatis dari work-log |
| ADM-04 | Rekonsiliasi data | 15% | ≥ 98% | Higher | Hasil rekonsiliasi dan exception | Sistem otomatis dari work-log |
| ADM-05 | Kehadiran & disiplin | 10% | ≥ 95% | Higher | Data absensi | Status absensi Supervisor |
| ADM-06 | Kepatuhan SOP | 5% | ≥ 95% | Higher | Checklist observasi | Supervisor |
| | **Total** | **100%** | | | | |

### 6.4 Kasir

| Kode | Indikator | Bobot | Target | Arah | Sumber Data | Yang Isi |
|---|---|---:|---|---|---|---|
| KSR-01 | Akurasi transaksi | 30% | ≥ 99% | Higher | Import laporan kasir | Import otomatis |
| KSR-02 | Selisih kas | 25% | 0 / minimal | Lower | Import: kas sistem vs kas aktual | Import otomatis |
| KSR-03 | Ketepatan laporan kas | 20% | 100% tepat waktu | Higher | Timestamp upload vs deadline | Sistem otomatis |
| KSR-04 | Kecepatan transaksi | 10% | ≥ 95% | Higher | Durasi transaksi dari laporan | Import otomatis |
| KSR-05 | Pelayanan | 10% | ≥ 90% | Higher | Checklist observasi | Supervisor |
| KSR-06 | Disiplin | 5% | ≥ 95% | Higher | Data absensi | Status absensi Supervisor |
| | **Total** | **100%** | | | | |

### 6.5 Gudang / Sparepart

| Kode | Indikator | Bobot | Target | Arah | Sumber Data | Yang Isi |
|---|---|---:|---|---|---|---|
| GUD-01 | Akurasi stok | 30% | ≥ 98% | Higher | Stok sistem vs hasil opname | Gudang input; sistem hitung; Supervisor verifikasi |
| GUD-02 | Selisih stok | 20% | ≤ 2% | Lower | Difference record | Sistem; lower is better |
| GUD-03 | Kecepatan penyediaan sparepart | 15% | ≥ 95% | Higher | Request time vs fulfilled time | Sistem / log / evidence |
| GUD-04 | Kelengkapan stok | 15% | ≥ 95% | Higher | Availability item wajib | Sistem / checklist |
| GUD-05 | Stock opname | 10% | 100% | Higher | Completion dan deadline | Sistem + Supervisor |
| GUD-06 | Kerapian gudang | 5% | ≥ 90% | Higher | Checklist observasi | Supervisor |
| GUD-07 | Disiplin | 5% | ≥ 95% | Higher | Data absensi | Status absensi Supervisor |
| | **Total** | **100%** | | | | |

### 6.6 Supervisor

| Kode | Indikator | Bobot | Target | Arah | Sumber Data | Yang Isi |
|---|---|---:|---|---|---|---|
| SUP-01 | Pencapaian target tim | 30% | **[Harus diputuskan]** | Higher | Agregasi KPI anggota tim | Sistem otomatis |
| SUP-02 | Kualitas kerja tim | 20% | **[Harus diputuskan]** | Higher | Agregasi kualitas/retur/error tim | Sistem + Manager |
| SUP-03 | Kedisiplinan tim | 15% | **[Harus diputuskan]** | Higher | Agregasi kehadiran tim | Sistem + Manager |
| SUP-04 | Penyelesaian komplain | 10% | **[Harus diputuskan]** | Higher | Complaint SLA record | Sistem / Manager |
| SUP-05 | Coaching & evaluasi karyawan | 10% | **[Harus diputuskan]** | Higher | Coaching record vs target | Supervisor input; Manager verifikasi |
| SUP-06 | Kepatuhan SOP | 10% | **[Harus diputuskan]** | Higher | Rubric/checklist Manager | Manager assessment |
| SUP-07 | Ketepatan laporan | 5% | **[Harus diputuskan]** | Higher | Timestamp submission | Sistem otomatis |
| | **Total** | **100%** | | | | |

> **Catatan:** Target Supervisor belum ditentukan. Wajib dilengkapi sebelum periode pertama dibuka.

### 6.7 Owner / Manager

Daftar KPI, bobot, dan target Owner/Manager **belum tersedia**. Sistem menyediakan template yang dapat dikonfigurasi, tetapi tidak boleh membuat indikator fiktif. Harus ditentukan sebelum go-live.

---

## 7. Alur Sistem End-to-End

### 7.0 Alur Servis

Pelayan menerima perangkat dan membuat tiket melalui mobile. Teknisi mengambil tiket atau ditugaskan oleh Supervisor/Manager, lalu mengerjakan diagnosis dan progres. Kasir mencatat estimasi; Pelayan mencatat persetujuan pelanggan sesuai aturan tiket. Teknisi meminta sparepart, Gudang memenuhi, dan Teknisi menerima. Teknisi melakukan QC beserta bukti teknis. Kasir menyelesaikan biaya/pembayaran, kemudian Pelayan menyerahkan perangkat dan menyediakan tautan feedback pelanggan. Pengecualian pembayaran memerlukan Manager yang berwenang. Setiap tindakan memeriksa cabang, pemilik tugas, status, dan versi baris.

### 7.1 Alur KPI

1. Super Admin menyiapkan skala, indikator, template, target, bobot, dan rubrik dengan urutan salin versi aktif, edit draft, validasi, lalu aktifkan untuk periode berikutnya. Admin KPI melengkapi cabang, assignment penilai, dan deadline periode.
2. Sistem mengambil snapshot organisasi dan menyiapkan fakta/entri melalui sinkronisasi terjadwal. Karyawan mencatat pekerjaan operasional tanpa submit KPI.
3. Supervisor mencatat status absensi, mengonfirmasi nilai objektif atau meminta koreksi sumber, dan memilih satu predikat untuk setiap KPI subjektif per hari.
4. Hasil Supervisor langsung menjadi nilai efektif dan dapat direkap tanpa menunggu tindakan Manager.
5. Manager dapat meninjau harian staf secara opsional, menyetujui semua indikator per karyawan, atau mengubah satu indikator dengan alasan; Manager tetap mengesahkan atau mengembalikan rekap staf sesuai workflow bulanan.
6. Setelah hasil tim tersedia, Manager menilai dan memfinalisasi KPI Supervisor yang ditugaskan kepadanya.
7. Admin KPI mempublikasikan setelah semua KPI disahkan. Skor/predikat kemudian terlihat oleh pemilik KPI; penguncian periode adalah tindakan terpisah.
8. Koreksi hasil final selalu melalui permintaan, persetujuan pihak lain, dan histori before/after teraudit. Perubahan sumber sebelum final membatalkan review bagian terdampak dan menolak approval hasil kedaluwarsa.

### 7.2 Status Periode

```
DRAFT → READY → OPEN → SUBMISSION_CLOSED → IN_REVIEW → WAITING_APPROVAL → PUBLISHED → LOCKED
                                                                          ↘ CANCELLED
```

| Status | Artinya |
|---|---|
| DRAFT | Konfigurasi belum final, bisa diubah |
| READY | Template, target, bobot, dan assignment tervalidasi |
| OPEN | Pekerjaan operasional dan penilaian fakta harian berlangsung |
| SUBMISSION_CLOSED | Input baru ditutup |
| IN_REVIEW | Review Supervisor berjalan |
| WAITING_APPROVAL | Menunggu approval Manager/Owner |
| PUBLISHED | Hasil final terlihat oleh karyawan |
| LOCKED | Tidak dapat diubah tanpa proses koreksi resmi |
| CANCELLED | Periode dibatalkan dengan alasan |

### 7.3 Status KPI Karyawan (Employee KPI)

```
Draft → Submitted → Under Review → Revision → Submitted → Under Review → Verified → Pending Approval → Approved → Locked
```

### 7.4 Status Item KPI

```
Not Started → Draft → Submitted → Under Review → Revision Required → [kembali ke Draft]
                                              ↘ Verified → Assessed → Locked
```

---

## 8. Alur per Role

### 8.0 Pelayan

Masuk melalui mobile, menerima perangkat, membuat tiket, mencatat persetujuan pelanggan, menyerahkan perangkat setelah QC dan pembayaran, serta menangani feedback dan komplain. Pelayan tidak melakukan diagnosis, progres teknis, atau QC.

### 8.1 Teknisi

Masuk melalui mobile, mengambil tiket atau menerima penugasan, lalu melakukan diagnosis, progres, permintaan/penerimaan sparepart, evidence teknis dan QC tiket miliknya. Fakta tiket menghasilkan KPI otomatis. Teknisi melihat status dan fakta; skor/predikat menunggu publikasi.

### 8.2 Admin Operasional dan Gudang

Admin Operasional mencatat work-log, kelengkapan dokumen dan rekonsiliasi melalui mobile/web. Gudang memakai mobile untuk pemenuhan sparepart, stok dan opname. Keduanya melihat KPI sendiri tanpa kewajiban submit.

### 8.3 Kasir

Kasir menggunakan mobile/web untuk estimasi, biaya final, pembayaran, dan upload laporan kasir. File diproses menjadi preview; validasi serta verifikasi sumber mengikuti workflow import. Tidak ada input skor KPI oleh Kasir.

### 8.4 Supervisor

Supervisor membuka Tugas untuk absensi, penilaian harian, coaching, dan rekap tim sesuai snapshot. Fakta objektif diverifikasi, penilaian subjektif memakai rubrik/predikat terkonfigurasi. Rekap lengkap dikirim ke Manager. Bagian yang dikembalikan harus diperbaiki dengan alasan dan histori. KPI Supervisor sendiri dinilai dan disahkan Manager; hasil sendiri menunggu publikasi.

### 8.5 Manager/Owner

Manager melihat hasil, bukti dan histori staf yang ditugaskan. Setelah penilaian Supervisor selesai, Manager dapat meninjau harian staf secara opsional, menyetujui semua indikator per karyawan, atau mengubah satu indikator dengan alasan. Tanpa tindakan Manager, hasil Supervisor tetap digunakan dalam rekap. Untuk KPI Supervisor, Manager mengerjakan penilaian dan finalisasi langsung. Manager tidak membuka/mempublikasikan periode dan tidak menyetujui KPI sendiri.

### 8.6 Administrasi dan Auditor

Admin KPI mengatur dan mempublikasikan periode setelah validasi/finalisasi lengkap. Super Admin memiliki seluruh akses website lintas role dan cabang. Auditor membaca laporan, histori dan audit lintas cabang tanpa aksi perubahan. Ketiganya memakai website.

---

## 9. Formula & Kalkulasi KPI

### 9.1 Jenis Target & Formula

#### Higher Is Better (ratio)

Dipakai untuk: TEK-01, TEK-02, TEK-04, CS-01 s/d CS-04, ADM-01 s/d ADM-04, KSR-01, KSR-03, GUD-01, GUD-03, dll.

```
raw_achievement = (actual / target) × 100
achievement = MIN(raw_achievement, cap)  ← default cap = 100
weighted_score = achievement × (weight / 100)
```

Contoh TEK-01: target 100 servis, aktual 110.
```
raw = (110 / 100) × 100 = 110%
achievement = MIN(110, 100) = 100%  [capped]
weighted_score = 100 × (25/100) = 25.00
```

#### Lower Is Better (threshold_penalty)

Dipakai untuk: TEK-03 (retur), GUD-02 (selisih stok), CS-05 (komplain).

```
if actual <= target : achievement = 100
if actual >= failure_limit : achievement = 0
otherwise : achievement = ((failure_limit - actual) / (failure_limit - target)) × 100
```

Contoh TEK-03: target ≤3%, failure_limit=6%, aktual=4%.
```
achievement = ((6 - 4) / (6 - 3)) × 100 = 66.67%
weighted_score = 66.67 × (15/100) = 10.00
```

Contoh TEK-03 aktual=2% (di bawah target = bagus):
```
actual(2) <= target(3) → achievement = 100%
weighted_score = 100 × (15/100) = 15.00
```

#### Zero Tolerance

Dipakai untuk: KSR-02 (selisih kas — idealnya nol).

```
if actual <= full_score_limit : achievement = 100
if actual >= failure_limit : achievement = 0
otherwise : achievement = ((failure_limit - actual) / (failure_limit - full_score_limit)) × 100
```

> Nilai `full_score_limit` dan `failure_limit` dalam rupiah atau persentase — **wajib dikonfigurasi sebelum periode pertama.**

#### Predikat dengan Panduan Rubrik

Dipakai untuk penilaian Supervisor pada TEK-05, TEK-06, CS-02, ADM-06, KSR-05, dan GUD-06. Kriteria rubrik ditampilkan sebagai panduan; Supervisor memilih satu predikat untuk keseluruhan KPI dan wajib menulis catatan jika nilainya di bawah target.

| Kode internal | Label default | Nilai default |
|---|---|---:|
| POOR | Perlu Perbaikan | 60% |
| FAIR | Cukup | 75% |
| GOOD | Baik | 85% |
| VERY_GOOD | Sangat Baik | 95% |
| STAR | Istimewa | 100% |

Nilai subjektif bulanan adalah rata-rata nilai predikat harian yang disetujui. Penilaian Manager terhadap KPI Supervisor (`SUP-*`) tidak berubah.

Contoh rubric SOP Teknisi:

| Criterion | Poin | Wajib |
|---|---:|:---:|
| Diagnosis sesuai prosedur | 1 | Ya |
| Penggunaan alat sesuai SOP | 1 | Ya |
| Pemeriksaan akhir | 1 | Ya |
| Dokumentasi lengkap | 1 | Ya |
| Keselamatan kerja | 1 | Ya |

#### Siklus Harian dan Agregasi Bulanan

- Entri unik berdasarkan item dan tanggal/cadence, dipersiapkan terjadwal tanpa kunjungan halaman atau submit karyawan.
- Nilai resmi staf berasal dari penilaian Supervisor atas fakta; nilai KPI Supervisor berasal dari penilaian Manager yang ditugaskan.
- Rumus, target dan bobot tetap mengikuti snapshot. Data sistem dengan cadence periode dipakai sekali, bukan dijumlahkan ulang per hari. Fakta harian diagregasi sesuai metrik/rubriknya.
- Sumber belum tersedia bukan nol. Denominator nol atau parameter tidak lengkap menghasilkan UNSCORABLE.
- Perubahan sumber membatalkan penilaian bagian terdampak dan menuntut review ulang sebelum finalisasi; pengulangan sinkronisasi dengan fakta sama tidak menggandakan entri.
- Kegagalan sinkronisasi tercatat di audit dan dapat diulang melalui perintah persiapan harian.

### 9.2 Total Skor & Rating

```
total_score = SUM(weighted_score semua item)
```

Kalkulasi internal memakai presisi 6 desimal. Item tidak dibulatkan sebelum dijumlahkan. Total final dibulatkan 2 desimal dengan mode HALF_UP.

### 9.3 Rating / Predikat

| Total Score | Predikat |
|---:|---|
| 95 – 100 | ⭐ Istimewa |
| 90 – 94.99 | Sangat Baik |
| 80 – 89.99 | Baik |
| 70 – 79.99 | Cukup |
| < 70 | Perlu Perbaikan |

> Ambang ini adalah **rekomendasi awal** dan harus dikonfirmasi pemilik bisnis.

### 9.4 Aturan Edge Case

| Kondisi | Perilaku |
|---|---|
| Target = 0 pada formula pembagian | Tidak boleh dipakai; gunakan zero_tolerance atau binary |
| Denominator = 0 | Item menjadi `UNSCORABLE`, blokir approval |
| Semua item belum ada data | Header tidak bisa maju ke approval |
| Actual negatif | Hanya diterima jika formula mengizinkan |
| Actual > 100% | Boleh, tapi achievement tetap di-cap |
| Failure limit tidak dikonfigurasi | `UNSCORABLE`, sistem tidak menebak |

### 9.5 Snapshot & Reproducibility

Saat periode diaktifkan, sistem menyalin ke setiap item KPI karyawan:
- Nama indikator, kode, bobot, target, unit, arah
- Formula key dan parameter
- Sumber data dan siapa yang boleh input
- Rubric criteria
- Cap achievement, rounding rule, versi rating

Recalculation selalu memakai snapshot ini — bukan konfigurasi master terkini. Setiap kalkulasi menghasilkan `calculation_run` yang menyimpan input, formula, output, dan timestamp.

---

## 10. Spesifikasi Fitur per Modul

### 10.1 Modul Autentikasi

**Fitur:**
- Login dengan email dan password.
- Logout dan logout semua sesi.
- Lupa password dengan reset link (single-use, time-limited).
- Manajemen sesi: lihat sesi aktif di perangkat apa, cabut sesi lain.
- Rate limiting pada endpoint login.
- Token Sanctum untuk mobile (scoped, named per device, revocable).
- Session + CSRF untuk web Filament.

**Keamanan:**
- Password di-hash dengan bcrypt.
- Token mobile tersimpan di platform secure storage.
- Nonaktifkan akun → semua token dan sesi langsung dicabut.

### 10.2 Modul Manajemen Karyawan & Organisasi

**Fitur:**
- CRUD karyawan: nama, nomor karyawan, jabatan, email, tanggal bergabung.
- Histori penempatan (cabang, jabatan, Supervisor) dengan effective date.
- Karyawan nonaktif tetap ada di histori — tidak dihapus.
- Pengelolaan Supervisor per tim.
- Satu karyawan satu jabatan aktif pada satu waktu.
- Akun login (Users) dipisah dari profil karyawan (Employees) — karyawan bisa ada tanpa akun.

### 10.3 Modul Master KPI

**Fitur:**
- CRUD definisi indikator (kode, nama, unit, tipe metrik, arah, sumber data).
- Template KPI per jabatan dengan versi.
- Per template version: indikator, bobot, target, formula, rubric, evidence requirement.
- Validasi: total bobot harus tepat 100% sebelum template bisa diaktifkan.
- Template aktif bersifat immutable — edit membuat versi baru.
- Skala, indikator, template, dan rubrik hanya dikelola Super Admin. Perubahan selalu melalui urutan salin versi aktif, edit draft, validasi, lalu aktifkan.
- Aktivasi skema ditolak jika rentang gap/overlap, nilai di luar 0-100, kode duplikat, atau pilihan manual bukan tepat lima.
- Preview tampilan "seperti yang dilihat karyawan" sebelum aktivasi.
- Diff antar versi template.

**Business Rules:**
- Bobot > 0 dan ≤ 100 per item.
- Total bobot = 100% (tidak boleh 99.99% atau 100.01%).
- Target numerik wajib untuk formula otomatis.
- Failure limit wajib untuk formula threshold_penalty dan zero_tolerance.
- Template Supervisor tidak bisa dipublish sebelum semua target diisi.

### 10.4 Modul Periode KPI

**Fitur:**
- Buat periode dengan nama, rentang tanggal, deadline input/review/approval.
- Pilih template aktif yang berlaku.
- Set cap skor, scheme rating, dan scope (seluruh toko / per jabatan).
- Validasi readiness sebelum periode dibuka: template valid, semua karyawan punya placement, semua deadline logis.
- Generate KPI karyawan: sistem generate snapshot per karyawan berdasarkan jabatan.
- Generate bersifat idempotent — tidak membuat duplikat jika dijalankan ulang.
- Monitor progress per jabatan dan per karyawan.

**Status transition guards:**

| Transisi | Guard |
|---|---|
| Draft → Ready | Template valid, target lengkap, bobot 100% |
| Ready → Open | Deadline belum lewat, minimal 1 karyawan eligible |
| Open → Closed | Dilakukan manual atau otomatis via scheduler |
| Closed → Locked | Semua KPI wajib sudah final atau ada override beralasan |

### 10.5 KPI Saya

Daftar indikator, target, bobot, sumber, status, fakta dan evidence milik sendiri. Skor, predikat dan hasil rubrik sendiri disembunyikan hingga publikasi. Histori tetap dapat dibuka setelah periode ditutup/dikunci. Tidak ada submit atau input aktual KPI oleh karyawan; pekerjaan dicatat lewat modul operasional.

### 10.6 Review Supervisor

Antrean fakta tim sesuai snapshot cabang dan assignment; absensi serta penilaian harian/cadence, verifikasi evidence, catatan revisi, dan pengiriman rekap bulanan. Hasil yang sudah dinilai tidak dinilai ulang saat pengiriman rekap. Indikator belum lengkap atau sumber yang berubah mencegah penerusan hasil kedaluwarsa.

### 10.7 Approval Manager

Detail hasil staf beserta bukti dan histori; tindakan Approve atau Return indikator tertentu dengan alasan. Manager hanya mengisi nilai untuk KPI Supervisor yang ditugaskan, lalu boleh mengesahkannya langsung. Self-assessment dan self-approval ditolak. Approval menghasilkan hasil final; publikasi serta penguncian periode dilakukan terpisah oleh Admin KPI.

### 10.8 Modul Correction (Koreksi Data Final)

**Fitur:**
- Jika ditemukan kesalahan pada KPI yang sudah disahkan atau dikunci.
- Authorized actor membuat correction request dengan alasan dan evidence.
- Butuh dual authorization: satu pihak request, pihak lain approve.
- Setelah disetujui: sistem jalankan koreksi, recalculate, simpan before/after.
- Versi sebelumnya tidak dihapus — revision counter bertambah.
- Notifikasi ke karyawan, Supervisor, dan Manager.

---

## 11. Import Laporan Kasir

### 11.1 Format yang Didukung

| Format | Status | Catatan |
|---|---|---|
| XLSX | ✅ Utama | Format yang direkomendasikan |
| CSV | ✅ Didukung | Perlu validasi delimiter dan encoding |
| PDF text-based | ⚠️ Terbatas | Hanya jika tabel dapat dibaca deterministik |
| PDF scan / OCR | ⛔ Manual review | Tidak boleh langsung ke KPI tanpa konfirmasi manusia |
| XLS legacy | ⚠️ Opsional | Hanya jika parser sandboxed dan fixture telah diuji |

### 11.2 Pipeline Import

```
Upload File
    ↓
Scan keamanan + hitung hash SHA-256
    ↓
Buat Import Batch (id, uploader, periode, status)
    ↓
Detect format + cari Mapping Template
    ↓ (asynchronous queue)
Parse → Normalize → Validate
    ↓
Preview: valid / warning / error / duplikat
    ↓
    ├── [Cancel] → tidak ada perubahan, file disimpan untuk audit
    └── [Konfirmasi] → commit ke cashier_transactions
                ↓
        Aggregate metrics
                ↓
        Hitung KPI Kasir otomatis
                ↓
        Supervisor verifikasi
```

### 11.3 Status Import Batch

```
uploaded → scanning → queued → parsing → normalizing → validating
→ ready_for_preview → needs_mapping / needs_review
→ confirmed → committing → completed / completed_with_warnings
→ failed / cancelled / superseded
```

### 11.4 Mapping Template

Mapping menentukan bagaimana kolom di file laporan dipetakan ke field sistem. Contoh:

```
Template: "Laporan Penjualan Aplikasi [Nama Kasir]"
Version: 1

Kolom File          → Field Sistem
────────────────────────────────────
"No Invoice"        → transaction_number
"Tanggal"           → transaction_date
"Nama Kasir"        → cashier_name
"Grand Total"       → transaction_amount
"Kas Sistem"        → system_cash_amount
"Kas Aktual"        → actual_cash_amount
"Durasi (detik)"    → transaction_duration_seconds
"Status"            → transaction_status
```

Mapping Template disimpan per versi. Template yang sudah dipakai tidak diedit — buat versi baru.

### 11.5 Validasi Import

**Level file:** format valid, ukuran dalam batas, tidak terenkripsi, hash belum pernah dipakai.

**Level schema:** header wajib ditemukan, tipe data valid (tanggal, nominal, dll.), kolom tidak berubah dari mapping.

**Level baris:** nomor transaksi ada, kasir bisa dipetakan ke karyawan, tanggal dalam window periode, nominal tidak negatif (kecuali refund), tidak duplikat.

**Level bisnis:** kasir aktif pada tanggal transaksi, periode laporan cocok dengan KPI period, kas sistem dan aktual mata uang sama.

### 11.6 Severity & Aksi

| Level | Dampak | Aksi |
|---|---|---|
| Info | Normalisasi minor | Tampil ringkas, bisa lanjut |
| Warning | Data bisa dipakai tapi perlu perhatian | User harus acknowledge sebelum lanjut |
| Error | Record tidak aman dipakai | Record ditolak; batch tidak bisa confirm jika ada critical |
| Critical | File/mapping tidak bisa dipercaya | Seluruh batch gagal |

### 11.7 Deduplikasi

Sistem mendeteksi duplikat melalui:
1. Hash SHA-256 file — jika file sama persis, langsung ditolak.
2. `source_application + transaction_number` — business key unik.
3. Fallback: tanggal + kasir + nominal + metode bayar.
4. Database unique constraint sebagai lapisan terakhir.

### 11.8 Formula KPI dari Import

```
KSR-01 Akurasi transaksi:
  valid_transactions / eligible_transactions × 100

KSR-02 Selisih kas:
  abs(actual_cash - system_cash) / eligible_transaction_amount × 100
  → gunakan zero_tolerance dengan full_score_limit dan failure_limit dalam rupiah

KSR-03 Ketepatan laporan:
  confirmed_at <= report_deadline → 100%, lewat deadline → penalty

KSR-04 Kecepatan transaksi:
  transactions_within_sla / transactions_with_duration_data × 100
  (hanya aktif jika field durasi tersedia di laporan)
```

---

## 12. Dashboard & Laporan

### 12.1 Dashboard Manager / Owner

**Ringkasan Kinerja** — halaman utama Manager:

```
[Filter Periode] [Filter Jabatan] [Export]

┌─────────────────┐ ┌─────────────────┐ ┌─────────────────┐ ┌─────────────────┐
│ Kelengkapan     │ │ Rata-rata Skor  │ │ Menunggu Review │ │ Menunggu        │
│ Submission      │ │ Periode Ini     │ │                 │ │ Approval        │
│ 87%  ↑ 5%      │ │ 91.2  ↑ 2.1    │ │ 8 KPI           │ │ 3 KPI           │
└─────────────────┘ └─────────────────┘ └─────────────────┘ └─────────────────┘

[Tren Skor per Periode — Line Chart]    [Distribusi Predikat — Donut Chart]

[Rata-rata Skor per Jabatan — Bar Chart]    [Aktivitas Workflow Terbaru]
```

**Konten:**
- Tren skor antar periode.
- Distribusi predikat (Istimewa, Sangat Baik, Baik, Cukup, Perlu Perbaikan).
- Rata-rata skor per jabatan.
- Top performers dan karyawan di bawah target (eligibility jelas).
- Antrean approval yang menunggu.
- Karyawan overdue (belum submit setelah deadline).

### 12.2 Dashboard Supervisor

Fokus pada antrean kerja:
- Jumlah anggota tim, status progress (belum mulai, draft, submit, revisi, selesai).
- Rata-rata skor tim sementara.
- KPI tim di bawah target.
- Daftar submission terbaru yang butuh di-review.
- Deadline review yang mendekat.

### 12.3 Dashboard Karyawan

- Progress pengisian KPI (bukan provisional score).
- Target vs aktual per indikator.
- Status per item: belum isi, draft, submitted, revision required, verified.
- Notifikasi revision dan feedback Supervisor.
- Hasil final setelah approved: skor, rating, breakdown, timeline.

### 12.4 Laporan yang Tersedia

| Laporan | Scope | Format |
|---|---|---|
| Rekap skor per periode | Semua karyawan | XLSX, PDF |
| Rekap per jabatan | Per jabatan | XLSX, PDF |
| Detail penilaian karyawan | Individual | PDF |
| Target vs aktual per KPI | Per jabatan | XLSX |
| Ranking karyawan | Per jabatan / semua | PDF |
| KPI di bawah target | Filter by threshold | XLSX |
| Kelengkapan submission & review | Progress monitoring | XLSX |
| Histori perubahan penilaian | Audit | XLSX |
| Coaching & action plan | Per karyawan | PDF |
| Import reconciliation | Kasir | XLSX |

### 12.5 Aturan Ranking

- Hanya KPI berstatus Locked yang masuk ranking final.
- Ranking utama per jabatan — perbandingan lintas jabatan diberi konteks.
- Tie rule: skor sama → bandingkan indikator bobot tertinggi → shared rank jika masih sama.
- Karyawan yang baru bergabung mid-period diberi status eligibility, tidak dipaksa masuk ranking.
- Karyawan dengan KPI dalam proses koreksi ditandai sebagai pending.

---

## 13. Notifikasi

### 13.1 Event & Penerima

| Event | Penerima | Channel |
|---|---|---|
| Periode dibuka | Semua karyawan terkait | In-app + Push |
| Deadline input mendekat (H-3, H-1) | Karyawan belum submit | In-app + Push |
| KPI disubmit | Supervisor | In-app + Push |
| Revision diminta | Karyawan | In-app + Push |
| Revision di-resubmit | Supervisor | In-app + Push |
| KPI diverifikasi / forwarded | Manager | In-app + Push |
| KPI di-return Manager | Supervisor | In-app + Push |
| KPI diapprove / final | Karyawan + Supervisor | In-app + Push |
| Import ready / gagal | Uploader + Supervisor verifikasi | In-app + Push |
| Koreksi diterapkan | Karyawan + Supervisor + Manager | In-app |
| Deadline review mendekat | Supervisor belum selesai | In-app + Push |

### 13.2 Aturan Notifikasi

- Notifikasi bukan sumber status — klik selalu mengambil data terbaru dari server.
- Deduplicate: event + entity + penerima yang sama tidak dikirim dua kali.
- Deep link: menuju screen yang sesuai dan authorized.
- Konten notifikasi tidak menampilkan data sensitif di lock screen.
- Email reminder (opsional MVP) dikirim via queue, tidak sinkron.

---

## 14. Arsitektur Sistem

### 14.1 Gambaran Umum

```
[Flutter Mobile App] ──── REST API /api/v1 ────┐
                                                 │
[Web Browser] ──── Laravel + Inertia React ───────────┤
                                                 │
[Aplikasi Kasir] ── XLSX/CSV/PDF ──── Import ───┘
                                                 │
                                         [Laravel Backend]
                                                 │
                              ┌──────────────────┼──────────────────┐
                              │                  │                  │
                          [MySQL]          [Object Storage]    [Queue/Cache]
                                                                     │
                                                              [Push Notification]
```

### 14.2 Stack Teknologi

| Komponen | Teknologi |
|---|---|
| Backend | Laravel 11 |
| Web Admin | Filament 3 |
| Mobile | Flutter (Android & iOS, 1 codebase) |
| Database | MySQL 8 |
| Cache & Queue | Redis |
| File Storage | Private Object Storage (S3-compatible) |
| Authentication Web | Session + CSRF (Laravel) |
| Authentication Mobile | Laravel Sanctum Token |
| Job Processing | Laravel Queue Worker |
| Scheduling | Laravel Scheduler |

### 14.3 Arsitektur Modular Monolith

Backend dibagi menjadi modul dengan batas yang jelas:

| Modul | Tanggung Jawab |
|---|---|
| Identity & Access | Login, token, session, role, permission |
| Organization | Employee, jabatan, departemen, tim, penempatan |
| KPI Catalog | Definisi, template, versi, rubric, rating |
| Period | Siklus periode, deadline, generate KPI |
| Assessment | Employee KPI, item, actual, evidence, submit |
| Calculation | Achievement, weighted score, total, rating |
| Review | Verifikasi Supervisor, rubric, revision, forward |
| Approval | Manager approve/return, locking, correction |
| Import | Upload, parsing, mapping, staging, validate, confirm |
| Reporting | Dashboard, ranking, trend, export |
| Notification | In-app, push, email, preferences |
| Audit & Compliance | Activity log, before/after, security event |

**Aturan antar modul:**
- Modul berkomunikasi melalui application service atau domain event.
- Controller dan Filament Resource tidak menulis langsung ke banyak agregat.
- Semua kalkulasi dilakukan oleh Calculation module.
- Audit ditulis dalam transaksi yang sama atau via outbox.

### 14.4 Struktur Folder Backend

```
app/
├── Modules/
│   ├── Assessment/
│   │   ├── Application/Commands/
│   │   ├── Application/Queries/
│   │   ├── Domain/Entities/
│   │   ├── Domain/Events/
│   │   └── Infrastructure/Models/
│   ├── Calculation/
│   ├── Import/
│   ├── Organization/
│   └── ...
├── Filament/
│   ├── Resources/
│   ├── Pages/
│   └── Widgets/
└── Http/Api/V1/
    ├── Controllers/
    └── Resources/
```

### 14.5 Struktur Folder Mobile (Flutter)

```
lib/
├── app/
│   ├── router/
│   └── theme/
├── core/
│   ├── api/
│   ├── auth/
│   ├── errors/
│   └── storage/
└── features/
    ├── dashboard/
    ├── my_kpi/
    ├── review/
    ├── approval/
    ├── imports/
    ├── notifications/
    └── profile/
```

---

## 15. Model Data & ERD

### 15.1 Kelompok Tabel

#### Identitas & Organisasi
```
users, employees, employee_placements
branches, positions, departments
roles, permissions, model_has_roles
```

#### Katalog & Periode KPI
```
kpi_definitions, kpi_templates, kpi_template_versions
kpi_template_items, kpi_rubrics, kpi_rubric_criteria
kpi_rating_schemes, kpi_rating_bands
kpi_periods, kpi_period_branches
```

#### Penilaian
```
employee_kpis, employee_kpi_items
kpi_daily_entries
kpi_actual_entries, kpi_evidences
kpi_submissions, kpi_reviews, kpi_review_items
kpi_assessments, kpi_assessment_answers
kpi_approvals, kpi_correction_requests
kpi_calculation_runs
```

#### Import
```
import_mapping_templates, import_mapping_versions
import_batches, import_raw_rows, import_normalized_rows
import_row_issues, cashier_transactions, import_confirmations
```

#### Cross-cutting
```
notifications, notification_deliveries
audit_events, security_events
outbox_messages, file_objects, workflow_events
```

### 15.2 Field Penting

**`employees`**
```
id (ULID), user_id (nullable), employee_number (unique)
name, position_id, department_id
employment_status, joined_at, ended_at (nullable)
```

**`employee_kpis`** — header KPI per karyawan per periode
```
id (ULID), period_id, employee_id, template_version_id
supervisor_employee_id_snapshot, manager_employee_id_snapshot
status, progress_percentage
final_score (nullable), rating_code (nullable)
revision_number, submitted_at, verified_at, approved_at, locked_at
row_version  ← untuk optimistic locking
```

**`employee_kpi_items`** — snapshot satu indikator
```
id, employee_kpi_id
definition_code_snapshot, name_snapshot
weight_snapshot, target_json_snapshot
formula_key_snapshot, formula_parameters_snapshot
source_type_snapshot, evidence_requirement_snapshot
rubric_snapshot (nullable)
status, actual_decimal (nullable), actual_json (nullable)
achievement_percentage (nullable), weighted_score (nullable)
calculation_status, row_version
```

**`kpi_daily_entries`** — nilai dan keputusan satu item pada satu tanggal
```
id, employee_kpi_item_id, entry_date (unique per item/tanggal)
employee_actual_decimal, employee_actual_json, employee_note
entry_status, employee_entered_by, employee_submitted_at
supervisor_actual_decimal, supervisor_actual_json, supervisor_answers_json
supervisor_score_percentage, supervisor_note, supervisor_assessed_by
supervisor_status, supervisor_assessed_at
manager_actual_decimal, manager_actual_json, manager_answers_json
manager_score_percentage, manager_note, manager_assessed_by
manager_status, manager_assessed_at
row_version, created_at, updated_at
```

Nilai efektif harian mengikuti keputusan Manager, lalu Supervisor, lalu input karyawan. Histori koreksi tetap tersimpan melalui `audit_events` dan `kpi_actual_entries`.

**`audit_events`**
```
id, occurred_at (UTC), actor_type, actor_id (nullable)
action, subject_type, subject_id
before_json (nullable), after_json (nullable)
reason (nullable), ip_address, request_id, correlation_id
```

**`kpi_template_versions`**
```
id, kpi_template_id, version_number
status (draft|active|retired)
effective_from, effective_until (nullable)
total_weight, rating_scheme_id
checksum, activated_by, activated_at
```

### 15.3 ERD High-Level

```
USERS ||--o| EMPLOYEES : profil
EMPLOYEES ||--o{ EMPLOYEE_PLACEMENTS : histori
POSITIONS ||--o{ EMPLOYEE_PLACEMENTS : jabatan
EMPLOYEES ||--o{ TEAM_ASSIGNMENTS : anggota_tim
EMPLOYEES ||--o{ TEAM_ASSIGNMENTS : supervisor

POSITIONS ||--o{ KPI_TEMPLATES : menerima
KPI_TEMPLATES ||--o{ KPI_TEMPLATE_VERSIONS : versi
KPI_TEMPLATE_VERSIONS ||--o{ KPI_TEMPLATE_ITEMS : item

KPI_PERIODS ||--o{ KPI_PERIOD_BRANCHES : scope
KPI_PERIODS ||--o{ EMPLOYEE_KPIS : generate

EMPLOYEE_KPIS ||--o{ EMPLOYEE_KPI_ITEMS : berisi
EMPLOYEE_KPI_ITEMS ||--o{ KPI_ACTUAL_ENTRIES : aktual
EMPLOYEE_KPI_ITEMS ||--o{ KPI_DAILY_ENTRIES : per_hari
EMPLOYEE_KPI_ITEMS ||--o{ KPI_EVIDENCES : bukti
EMPLOYEE_KPI_ITEMS ||--o{ KPI_REVIEW_ITEMS : direview
EMPLOYEE_KPI_ITEMS ||--o{ KPI_ASSESSMENTS : dinilai

IMPORT_BATCHES ||--o{ CASHIER_TRANSACTIONS : commit
CASHIER_TRANSACTIONS }o--o{ EMPLOYEE_KPI_ITEMS : sumber

USERS ||--o{ AUDIT_EVENTS : actor
EMPLOYEE_KPIS ||--o{ AUDIT_EVENTS : tercatat
```

### 15.4 Constraint & Index Penting

- Unique: `employee_id + period_id` pada employee_kpis.
- Unique: `employee_kpi_item_id + entry_date` pada kpi_daily_entries.
- Unique: `template_id + version_number` pada kpi_template_versions.
- Unique: transaction business key per source application.
- Index: period/status, supervisor/status, manager/status, employee/period/date, audit subject/time.
- Check constraint: total weight per template version = 100.0000.
- FK RESTRICT pada histori kritis (tidak bisa hapus yang masih direferensikan).
- Optimistic locking via `row_version` pada form review/approval.

---

## 16. Keamanan & Anti-Manipulasi

### 16.1 Prinsip Utama

1. **Separation of duties** — input, verifikasi, dan approval dilakukan pihak berbeda.
2. **Server-authoritative** — client tidak dipercaya untuk skor, status, timestamp, atau formula.
3. **Objective data first** — gunakan cross-role, import, atau sistem; manual rating hanya untuk aspek subjektif.
4. **Lock after submit** — karyawan tidak bisa ubah data sampai ada revision request.
5. **Immutable history** — perubahan menyimpan before/after, actor, reason, dan timestamp.
6. **No silent delete** — data proses/final tidak dihapus melalui UI normal.
7. **Dual authorization** — correction data final butuh dua pihak berbeda.
8. **Idempotency** — submit/import/retry tidak membuat data ganda.

### 16.2 Threat & Control Matrix

| Risiko | Contoh | Kontrol Preventif | Kontrol Detektif |
|---|---|---|---|
| Karyawan ketik skor tinggi | Kirim `weighted_score=25` dari mobile | API allow-list DTO; kalkulasi server | Security event untuk over-posting |
| Edit setelah submit | Request update pada data terkunci | State guard + optimistic lock | Audit failed transition |
| Supervisor ubah aktual | Ganti nilai 90 → 105 | API review hanya terima decision/note/rubric | Before/after log |
| Self-approval | Manager approve KPI sendiri | Policy subject/actor separation | Approval audit report |
| Evidence diganti diam-diam | Upload file baru dengan nama sama | Immutable object key + SHA-256 + versioned evidence | Hash mismatch alert |
| Duplicate import | File sama diupload dua kali | Hash file + business key + unique constraint | Duplicate report |
| Deadline dimanipulasi | Jam HP diubah | Timestamp server | Device vs server time log |
| Template lama berubah | Bobot master diedit | Period snapshot + checksum | Reproducibility test |

### 16.3 File Security

- MIME sniffing + extension check + size limit.
- Malware/antivirus scan sebelum file bisa diakses reviewer.
- File disimpan private — tidak ada URL publik permanen.
- Object key random dan immutable — nama asli hanya metadata.
- SHA-256 tersimpan untuk setiap file.
- Download URL bersifat short-lived (signed URL) setelah authorization check.
- Formula injection protection: sel yang diawali `=`, `+`, `-`, `@` di-escape saat export.

### 16.4 API Security

- Semua endpoint butuh autentikasi.
- Permission diperiksa di backend — menyembunyikan tombol di UI bukan kontrol keamanan.
- Query selalu di-scope per actor — tidak fetch semua data lalu filter di client.
- Rate limiting pada mutation/upload/export.
- Error response tidak membocorkan stack trace atau internal detail.
- CORS hanya untuk origin resmi.
- HTTPS wajib; secure cookies, same-site, HSTS.

---

## 17. UI/UX & Design System

### 17.1 Prinsip Umum

```
Web   = Control Center (konfigurasi, review massal, laporan, analytics)
Mobile = Daily Actions (input cepat, submit, review, approval)
```

Hierarki informasi KPI:
```
Score → Status → Target → Actual → Source → Evidence → Verification → Approval
```

### 17.2 Token Visual

| Token | Nilai | Penggunaan |
|---|---|---|
| Primary / Lime | `#BEFF50` | Tombol utama, tab aktif, highlight |
| Background | `#F8FAFC` | Latar halaman |
| Surface / Card | `#FFFFFF` | Kartu konten |
| Parchment | `#F5F5EB` | Kartu ringkasan, filter panel |
| Ink (teks) | `#14140F` | Teks utama |
| Muted | `#6E6E64` | Teks pendukung |
| Dark surface | `#30302A` | Sidebar gelap |
| Border | `#D2D2C8` | Divider, border input |

### 17.3 Warna Status

| Status | Warna | Label |
|---|---|---|
| Draft | Abu-abu | Draft |
| Submitted | Biru | Menunggu Review |
| Under Review | Ungu | Sedang Direview |
| Revision | Amber/Orange | Perlu Revisi |
| Verified | Teal/Cyan | Terverifikasi |
| Pending Approval | Kuning | Menunggu Approval |
| Approved/Locked | Hijau | Selesai |
| Data Exception | Merah | Data Perlu Diperiksa |

> Warna **tidak boleh** menjadi satu-satunya pembeda. Selalu sertakan teks label dan ikon.

### 17.4 Layar Prioritas Mobile (Flutter)

1. Login dan manajemen sesi
2. Home Employee (periode aktif, progress, action items)
3. KPI Saya — daftar indikator
4. Detail item KPI
5. Input aktual + upload evidence
6. Submit confirmation bottom sheet
7. Revision required — perbaiki item
8. Riwayat KPI + timeline
9. Home Supervisor — antrean review tim
10. Review item per karyawan
11. Supervisor checklist/rubric subjektif
12. Manager overview + antrean approval
13. Approval detail + approve/return
14. Kasir upload laporan + progress import
15. Notification center
16. Profile

### 17.5 Aksesibilitas

- Kontras teks minimum WCAG 2.2 AA.
- Touch target mobile minimum 44×44px.
- Semua aksi dapat dilakukan dengan keyboard (web).
- Status tidak hanya dibedakan warna — selalu ada label dan ikon.
- Semua chart punya ringkasan teks atau tabel alternatif.
- Form input punya label, placeholder, unit, dan pesan error yang jelas.

---

## 18. Kebutuhan Non-Fungsional

### 18.1 Performa

| Metrik | Target |
|---|---|
| Latency halaman umum (P95) | ≤ 500ms |
| Latency dashboard (P95) | ≤ 2 detik |
| Mutation umum (P95) | ≤ 1 detik |
| Upload/import besar | Asynchronous, ada progress indicator |
| Export laporan besar | Asynchronous, notifikasi saat selesai |

### 18.2 Reliabilitas

- Semua mutation kritis berjalan dalam database transaction.
- Job kalkulasi dan import bersifat idempotent.
- Backup database terjadwal dan restore diuji berkala.
- Error kalkulasi tidak mengubah sebagian data final — all or nothing.
- Semua timestamp disimpan UTC, ditampilkan sesuai timezone toko.

### 18.3 Skalabilitas

- Batch generate KPI menggunakan chunking dan queue.
- Import file besar diproses asynchronous.
- Dashboard menggunakan aggregate query dengan index yang tepat atau cache dengan invalidation jelas.

### 18.4 Maintainability

- Enum untuk semua status, arah metrik, tipe formula.
- Unit test untuk semua strategi kalkulasi.
- Feature test untuk workflow, policy, dan scope.
- OpenAPI spec untuk semua endpoint API.
- Migration backward-compatible bila memungkinkan.
- CI: linter + formatter + test + static analysis + build wajib hijau sebelum deploy.

### 18.5 Privasi & Data

- Evidence dan laporan keuangan tersimpan private — tidak ada URL publik.
- Export mengandung watermark/user/timestamp bila diperlukan.
- Non-production environment memakai data sanitized/anonim.
- Retention policy untuk evidence, audit log, dan akun nonaktif sesuai kebijakan perusahaan.
- Audit tidak menyimpan password, token, atau data rahasia.

---

## 19. Roadmap Implementasi

### Phase 0 — Discovery & Keputusan Bisnis (2–3 minggu)

**Output:**
- Target numerik semua KPI Supervisor dikunci.
- KPI, bobot, dan target Owner/Manager ditentukan.
- Nilai konkret untuk placeholder `≥ target unit/bulan`, `failure_limit` setiap KPI lower.
- Keputusan: threshold selisih kas (rupiah atau persentase).
- Keputusan: apakah skor bisa melebihi 100 (bonus), atau cap 100.
- Ambang predikat final dikonfirmasi.
- Sumber data setiap KPI dikonfirmasi (manual, import, sistem).
- Reviewer dan approver per cabang ditentukan.
- Sample laporan kasir asli (sudah disanitasi) tersedia untuk uji import.
- Wireframe 6 alur utama disetujui.

**Exit gate:** Tidak ada formula atau actor responsibility yang ambigu.

### Phase 1 — Fondasi Backend (2–3 minggu)

- Setup Laravel, Filament, MySQL, Redis, CI/CD.
- Autentikasi: login, token, session, password reset.
- Manajemen user, employee, histori penempatan.
- Role & permission (Spatie).
- Jabatan dan struktur organisasi dasar.
- Seeder: roles, jabatan, KPI definitions.

**Exit gate:** User bisa login, role ter-assign, seed data masuk.

### Phase 2 — Master KPI & Template (2–3 minggu)

- CRUD KPI definitions.
- Template KPI per jabatan dengan versi.
- Input bobot, target, formula, rubric, evidence requirement.
- Validasi total bobot = 100%.
- Publish template → immutable.
- Rating scheme dan grade threshold.
- Seed template semua jabatan (kecuali Owner dan Supervisor yang belum ada target).

**Exit gate:** Template Teknisi dengan target lengkap bisa dipublish.

### Phase 3 — Periode & Generate KPI (2 minggu)

- CRUD periode dengan deadline dan scope.
- Validasi readiness: template valid, karyawan punya placement, deadline logis.
- Generate KPI karyawan sebagai snapshot.
- Transisi status periode.
- Web dashboard sederhana untuk melihat progress.

**Exit gate:** Periode bisa diaktifkan, KPI ter-generate per karyawan berdasarkan jabatan.

### Phase 4 — Alur Karyawan & Supervisor (3–4 minggu)

- API dan mobile: KPI Saya, detail item, input aktual, upload evidence, submit.
- API dan web: review Supervisor, verifikasi, checklist rubric, request revisi.
- KPI Engine: kalkulasi achievement, weighted score, total, rating.
- Audit trail dan workflow events.
- Notifikasi core: submit, revisi, verify.

**Exit gate:** E2E Teknisi lengkap lulus (submit → revisi → resubmit → verify), termasuk test skor kalkulasi.

### Phase 5 — Approval Manager & Locking (2 minggu)

- Approval Manager: approve/return.
- Final locking atomik.
- Correction flow dengan dual authorization.
- Notifikasi approval dan final.
- Filament web untuk Manager: dashboard, antrean approval, detail breakdown.

**Exit gate:** E2E lengkap sampai locked, test self-approval ditolak.

### Phase 6 — Import Laporan Kasir (3–4 minggu)

- Upload file (XLSX, CSV).
- Parser, normalisasi, validasi.
- Mapping template management.
- Preview dan konfirmasi import.
- Deduplikasi.
- Hitung KPI Kasir dari hasil import.
- PDF text-based (opsional sesuai kebutuhan).
- Import observability dan fixture test.

**Exit gate:** Sample laporan kasir direkonsiliasi dan duplicate import terbukti ditolak.

### Phase 7 — Dashboard, Laporan, & Flutter (3–4 minggu)

- Dashboard lengkap Manager, Supervisor, Karyawan.
- Ranking, trend, dan semua laporan.
- Export XLSX dan PDF.
- Flutter mobile: semua layar prioritas MVP.
- Offline read cache.

**Exit gate:** Semua layar mobile usable di perangkat target, laporan bisa diekspor.

### Phase 8 — Hardening & Go-Live (2–3 minggu)

- Security review dan penetration test dasar.
- Load test sesuai jumlah karyawan.
- Backup dan restore drill.
- UAT per role.
- Training Manager, Supervisor, Karyawan, Admin.
- Pilot satu toko → rollout bertahap.
- Support runbook dan incident procedure.

**Exit gate:** Semua acceptance criteria lulus, backup terbukti bisa di-restore.

---

## 19A. Penilaian Tim Supervisor dan Manager

- Supervisor dan Manager memakai menu **Penilaian Tim**. Isi daftar mengikuti penugasan, cabang, role, dan larangan menilai diri sendiri pada snapshot KPI.
- Tab **Belum selesai** hanya menampilkan nama karyawan yang memiliki tindakan wajib. Tab **Selesai** hanya menampilkan karyawan yang memiliki pekerjaan wajib yang sudah disiapkan dan tidak lagi memiliki tindakan wajib tertunda. Data yang belum disiapkan tidak dianggap selesai.
- Daftar melintasi seluruh tanggal pada periode yang dapat ditindaklanjuti. Kontrol **Filter tanggal** hanya membatasi pekerjaan harian pada tanggal tersebut dan mengabaikan pekerjaan bulanan.
- Setiap baris menampilkan nama, jabatan dan cabang, satu kalimat kondisi, serta satu tindakan konkret: **Isi kehadiran**, **Nilai sekarang**, **Periksa masalah**, **Tinjau rekap**, atau **Sahkan hasil**. Urutan tetap mengikuti revisi, prasyarat kehadiran, deadline, dan pekerjaan tertua.
- Tindakan harian membuka halaman penilaian lama dengan `date` dan `kpi_id`. Dalam mode fokus halaman hanya memuat satu karyawan, menempatkan kehadiran sebelum indikator manual atau rubrik, lalu indikator otomatis. Pemilih tanggal dan tab Staf/Supervisor tidak ditampilkan.
- Konfirmasi otomatis Supervisor berada di halaman fokus dan hanya tersedia jika seluruh indikator `system`, `import`, dan `cross_role` yang relevan sudah lengkap. Operasi tetap atomik serta tidak mengubah kehadiran, rubrik, indikator manual, atau sumber resmi.
- Setelah tindakan terakhir selesai, halaman fokus menampilkan konfirmasi dan tautan kembali ke **Penilaian Tim**. Akses tanpa `kpi_id` mempertahankan antrean lama untuk kompatibilitas.
- Tinjauan harian staf oleh Manager tersedia sebagai **Tinjauan Opsional** dan tidak memengaruhi tab maupun jumlah pekerjaan wajib. **Hasil KPI**, **Coaching**, dan **Riwayat** tersedia sebagai menu terpisah sesuai hak akses.
- Mobile membuka KPI yang dipilih langsung untuk penilaian harian, tinjauan bulanan, atau pengesahan tanpa melewati antrean kedua.
- Layar dan endpoint lama tetap tersedia untuk detail, notifikasi, laporan, riwayat, dan kompatibilitas tautan lama.

## 20. Acceptance Criteria

### AC-01 — Template & Validasi Bobot
- [ ] Template tidak bisa dipublish jika total bobot bukan tepat 100%.
- [ ] Template aktif bersifat immutable — edit membuat versi baru.
- [ ] Periode hanya bisa memakai template yang sudah published dan berlaku.

### AC-02 — Snapshot
- [ ] Perubahan template setelah periode aktif tidak mengubah KPI yang sudah di-generate.
- [ ] Recalculation menghasilkan skor yang sama jika data sama.

### AC-03 — Pekerjaan dan KPI Karyawan

- [ ] Tiap role dapat login hanya pada platform yang diizinkan; URL/API langsung dan sesi lama memakai aturan yang sama.
- [ ] Pekerjaan operasional menjadi fakta KPI tanpa submit karyawan.
- [ ] Skor/predikat sendiri sebelum publikasi tidak bocor melalui detail, harian, dashboard, ekspor, koreksi atau notifikasi.
- [ ] Histori milik sendiri tetap dapat dibaca setelah periode ditutup.

### AC-04 — Penilaian dan Rekap Supervisor

- [ ] Supervisor hanya menilai tim sesuai snapshot cabang/assignment.
- [ ] Rekap staf dapat diteruskan dari hasil harian lengkap tanpa penilaian ulang tiap indikator.
- [ ] Sumber berubah membatalkan review terdampak; pengulangan sync tidak menggandakan entri.

### AC-05 — Pengesahan dan Publikasi

- [ ] Manager dapat meninjau hasil harian staf setelah Supervisor, menyetujui semua per karyawan, atau mengubah satu indikator dengan alasan; tahap ini opsional dan tidak menahan rekap.
- [ ] Satu Manager dapat menilai dan memfinalisasi KPI Supervisor; self-assessment/self-approval tetap ditolak.
- [ ] KPI Supervisor menunggu hasil tim, tetapi anggota tim tidak menunggu KPI Supervisor.
- [ ] Publikasi menunggu seluruh KPI approved; lock merupakan langkah terpisah dan koreksi final teraudit.

### AC-06 — Kalkulasi
- Rekap bulanan menghitung ulang setelah fakta/keputusan penilai yang berwenang berubah.
- Agregasi mengikuti metrik dan cadence; nilai sistem satu periode tidak dijumlahkan per hari.
- [ ] Formula higher/lower/zero_tolerance menghasilkan hasil yang benar (ada test matrix).
- [ ] Cap 100% diterapkan secara konsisten.
- [ ] Denominator nol menghasilkan UNSCORABLE, bukan error atau nilai palsu.
- [ ] Total skor = sum weighted score semua item dengan presisi yang benar.

### AC-07 — Import Kasir
- [ ] File asli, hash, uploader, mapping version, dan periode tersimpan.
- [ ] Upload tidak langsung mengubah KPI — butuh konfirmasi.
- [ ] Preview menampilkan valid/warning/error/duplikat sebelum konfirmasi.
- [ ] Duplikat file (hash sama) ditolak otomatis.
- [ ] Duplikat transaksi (business key sama) tidak masuk dua kali.
- [ ] PDF scan tidak auto-commit ke KPI tanpa verifikasi manual.

### AC-08 — Keamanan
- [ ] Tidak ada IDOR — user tidak bisa akses KPI orang lain melalui manipulasi ID.
- [ ] Over-posting (kirim field terlarang) diabaikan atau di-reject.
- [ ] Token yang di-revoke tidak bisa dipakai.
- [ ] File tidak bisa didownload dengan URL expired.
- [ ] Audit mencatat semua perubahan material dengan before/after.

### AC-09 — Correction
- [ ] Correction pada KPI Locked butuh dual authorization.
- [ ] Versi sebelumnya tidak dihapus — revision counter bertambah.
- [ ] Recalculation memakai snapshot yang sama.

### AC-10 — UX
- [ ] Dashboard menampilkan action items, bukan hanya grafik.
- [ ] Status selalu punya teks + warna + ikon.
- [ ] Progress pengisian tidak disalahartikan sebagai skor akhir.
- [ ] Source label (dari mana angka ini) ditampilkan pada detail item.
- [ ] Error selalu punya pesan yang jelas dan cara memperbaikinya.

---

## 21. Keputusan Bisnis yang Wajib Dikunci

Implementasi tidak bisa dimulai sebelum keputusan berikut dikunci. Setiap keputusan dicatat dalam ADR/PDR.

| # | Keputusan | Baseline Rekomendasi | PIC |
|---|---|---|---|
| 1 | Target numerik setiap KPI Supervisor (SUP-01 s/d SUP-07) | Tentukan berdasarkan data historis tim | Manager/HR |
| 2 | KPI, bobot, dan target Owner/Manager | Tentukan berdasarkan fokus bisnis | Owner |
| 3 | Nilai konkret untuk `≥ target unit/bulan` Teknisi (TEK-01) | Contoh: 80 unit/bulan per Teknisi | Manager |
| 4 | Threshold selisih kas (KSR-02): full_score_limit dan failure_limit | Contoh: toleransi Rp 50.000, gagal di Rp 200.000 | Finance/Owner |
| 5 | Failure limit untuk KPI lower is better (TEK-03 retur, GUD-02 selisih stok) | Contoh: retur gagal di 6%, stok gagal di 5% | Manager |
| 6 | Apakah skor bisa melebihi 100 (overachievement bonus)? | Default: cap 100 | Owner |
| 7 | Ambang predikat final | Rekomendasi: 95/90/80/70 | Manager/HR |
| 8 | Sumber data setiap KPI: manual, import, atau integrasi sistem lain | Tentukan per indikator | Manager/IT |
| 9 | Reviewer dan approver untuk setiap jabatan (termasuk siapa review Supervisor) | Manager mereview Supervisor | Owner/Manager |
| 10 | Grace window retur Teknisi (berapa hari setelah akhir periode retur masih dihitung) | Contoh: 3 hari kerja setelah akhir bulan | Manager/Owner |
| 11 | Kebijakan koreksi setelah KPI Published/Locked | Dual authorization, audit lengkap | Owner |
| 12 | Retention period evidence, audit log, dan akun nonaktif | Sesuai kebijakan perusahaan / regulasi | Owner/Legal |
| 13 | MFA (Multi-Factor Authentication) wajib untuk Manager/Owner? | Sangat direkomendasikan | Owner/IT |
| 14 | Siapa yang boleh melihat ranking individu lengkap? | Manager + Supervisor (scope tim) | Manager/HR |
| 15 | Nama dan logo aplikasi | — | Owner |

> **Aktivasi periode produksi pertama diblokir** jika keputusan nomor 1–11 belum dikunci.

---

*Dokumen ini adalah living document — perubahan yang mempengaruhi formula, fairness, atau security harus melalui review dan dicatat dalam ADR/PDR.*
