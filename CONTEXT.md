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
