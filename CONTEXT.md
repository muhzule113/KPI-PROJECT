# Konteks Domain KPI

Konteks ini mendefinisikan kepemilikan nilai KPI dan alur penilaian berjenjang untuk karyawan toko dan servis.

## Language

**KPI periode**:
Snapshot KPI seorang karyawan untuk satu periode penilaian bulanan.
_Avoid_: KPI milik karyawan, nilai yang diisi karyawan

**Entri KPI harian**:
Catatan satu indikator pada satu tanggal yang disiapkan sistem dan menjadi unit review Supervisor serta Manager.
_Avoid_: submission karyawan

**Nilai sistem**:
Nilai yang dihitung dari data operasional terverifikasi, seperti tiket servis, transaksi, absensi, atau stok.
_Avoid_: input manual karyawan

**Review Supervisor**:
Validasi pertama atas seluruh indikator KPI harian anggota tim yang ditugaskan kepada Supervisor.
_Avoid_: approval parsial

**Penilaian akhir Manager**:
Validasi atau koreksi terakhir setelah semua indikator pada KPI harian seorang karyawan selesai direview Supervisor.
_Avoid_: review sebelum Supervisor selesai

**Nilai resmi harian**:
Nilai Manager jika dikoreksi, nilai Supervisor jika tidak dikoreksi Manager, atau nilai sistem untuk indikator yang bersumber dari sistem.

**Agregasi bulanan**:
Perhitungan nilai periode dari hasil penilaian akhir harian; persentase dan rubrik dirata-ratakan, sedangkan unit hitungan atau uang dijumlahkan.

**Catatan absensi harian**:
Satu catatan untuk satu karyawan pada satu tanggal di dalam periode KPI yang berstatus `OPEN`.
_Avoid_: absensi lintas periode aktif atau dua catatan untuk tanggal yang sama

**Hari kerja absensi**:
Senin sampai Jumat. Kalender hari libur belum dikelola pada MVP, sehingga hari libur nasional tetap mengikuti aturan hari kerja sampai kalender tersebut tersedia.

**Status absensi**:
`Hadir` dan `Terlambat` adalah hari kerja yang dihitung sebagai hadir; `Izin` dan `Sakit` adalah ketidakhadiran beralasan yang dikeluarkan dari pembagi; `Alpha` dan hari kerja tanpa catatan masuk pembagi tetapi tidak masuk pembilang.

**Rasio kehadiran**:
`hari Hadir/Terlambat ÷ (hari kerja - hari Izin/Sakit) × 100`. Jika seluruh hari kerja dikecualikan karena Izin/Sakit, rasio tidak dihitung.

## Tanggung jawab role

**Admin sistem**:
Mengelola konfigurasi, master data, periode, dan monitoring KPI. Admin sistem tidak melakukan review, penilaian, atau approval KPI karyawan.

**Supervisor**:
Melakukan review dan penilaian anggota tim yang tercatat sebagai bawahannya pada snapshot KPI.

**Manager**:
Melakukan penilaian akhir dan approval KPI setelah review Supervisor selesai, hanya untuk KPI yang ditugaskan kepadanya.
