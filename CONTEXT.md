# Konteks Domain KPI

Konteks ini mendefinisikan istilah yang dipakai dalam alur KPI dan pekerjaan operasional toko serta servis.

## Istilah KPI

**Periode KPI**:
Rentang penilaian bulanan yang memiliki konfigurasi, deadline, dan status workflow sendiri.
_Avoid_: membuka periode sebelum konfigurasi lengkap

**Readiness periode**:
Kondisi ketika template aktif, target, bobot 100%, sumber, cabang, penilai, dan deadline telah lengkap sehingga periode boleh dibuka.

**KPI karyawan**:
Snapshot KPI seorang karyawan untuk satu periode, termasuk cabang, jabatan, Supervisor, dan Manager yang berlaku saat KPI dibuat.
_Avoid_: mengubah histori karena karyawan pindah cabang atau jabatan

**Fakta KPI**:
Data hasil pekerjaan dari modul operasional, impor, atau catatan penilai yang menjadi input kalkulasi. Fakta berbeda dari skor.

**Entri KPI harian**:
Catatan unik satu indikator pada satu tanggal atau cadence yang disiapkan sistem dan dinilai oleh penilai yang ditugaskan.
_Avoid_: submission KPI oleh karyawan, entri ganda dari sinkronisasi ulang

**Data belum tersedia**:
Fakta yang belum diterima dari sumber resminya. Nilainya tetap kosong dan tidak dianggap nol.

**UNSCORABLE**:
Hasil kalkulasi ketika indikator tidak dapat dihitung secara sah, misalnya karena denominator nol atau parameter wajib belum tersedia.

**Penilaian staf**:
Supervisor memeriksa fakta dan mengisi indikator manual anggota tim per hari/cadence. Setelah itu Manager dapat mengonfirmasi atau mengubah hasil harian secara opsional; perubahan wajib memiliki alasan dan menjadi nilai efektif.

**Rekap staf**:
Hasil bulanan yang dibentuk dari fakta dan penilaian Supervisor yang lengkap, kemudian diteruskan kepada Manager yang ditugaskan.

**Pengesahan staf**:
Keputusan Manager untuk menyetujui rekap atau mengembalikan bagian tertentu dengan alasan. Tinjauan harian Manager tidak mengubah fakta sumber dan tidak menghambat rekap bila dilewati.

**KPI Supervisor**:
KPI yang dinilai dan difinalisasi langsung oleh Manager yang ditugaskan setelah hasil tim tersedia. Pengecualian ini tidak mengizinkan penilaian atau approval diri sendiri.

**Publikasi**:
Tindakan Admin KPI untuk membuka skor dan predikat kepada karyawan setelah seluruh KPI periode disahkan.

**Penguncian**:
Langkah terpisah setelah publikasi yang menutup perubahan periode biasa. Koreksi hasil final tetap melalui permintaan dan persetujuan pihak lain yang diaudit.

**Perubahan sumber**:
Perubahan fakta sebelum finalisasi yang membatalkan review bagian terdampak dan mewajibkan penilaian ulang sebelum approval.

**Cakupan data**:
Hak membaca berdasarkan pemilik, snapshot cabang, dan assignment penilai. Admin KPI dan Auditor dapat membaca lintas cabang sesuai tanggung jawabnya; Auditor selalu hanya baca.

## Istilah operasional

**Tiket servis**:
Catatan penerimaan satu perangkat yang dibuat Pelayan dan menjadi sumber identitas seluruh alur servis.

**Penanggung jawab tiket**:
Teknisi yang mengambil tiket atau ditugaskan oleh Supervisor/Manager dan menjadi satu-satunya pelaksana diagnosis, progres teknis, serta QC tiket tersebut.

**Permintaan sparepart**:
Permintaan Teknisi pada tiket yang dipenuhi Gudang sesuai cabang dan kemudian diterima kembali oleh Teknisi.

**QC teknis**:
Pemeriksaan fungsi oleh Teknisi penanggung jawab sebelum pekerjaan teknis dinyatakan selesai.

**Pembayaran**:
Pencatatan biaya akhir dan pelunasan oleh Kasir. Pengecualian pembayaran mengikuti persetujuan yang terekam dalam workflow.

**Serah terima**:
Penyerahan perangkat oleh Pelayan setelah pekerjaan teknis dan syarat pembayaran selesai.

## Platform dan tanggung jawab

- Semua role menggunakan aplikasi web responsif yang sama dari ponsel atau komputer; tidak ada runtime Flutter.
- Super Admin mengelola skala, indikator, template, dan rubrik; Admin KPI mengelola periode, assignment penilai, konfigurasi import, monitoring, dan publikasi.
- Super Admin memiliki seluruh akses lintas role dan cabang.
- Admin KPI dan Auditor tidak memperoleh hak operasional atau penilaian.
- Server memeriksa status akun, profil, capability, kepemilikan, assignment, cabang, dan status data pada setiap request, tanpa mempercayai ukuran layar atau perangkat.

## Absensi

**Catatan absensi harian**:
Satu catatan untuk satu karyawan pada satu tanggal di dalam periode KPI yang berstatus `OPEN`.

**Hari kerja absensi**:
Senin sampai Jumat. Kalender hari libur belum dikelola pada MVP.

**Rasio kehadiran**:
`hari Hadir/Terlambat ÷ (hari kerja - hari Izin/Sakit) × 100`. Jika seluruh hari kerja dikecualikan, rasio tidak dihitung.
