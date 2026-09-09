# Konteks KPI Manual Harian

Aplikasi ini mencatat penilaian KPI manual setiap hari dan membentuk hasil bulanan yang disahkan Manager.

## Penilaian

**Lembar harian**:
Catatan status kerja dan seluruh nilai indikator seorang pegawai pada satu tanggal.
_Avoid_: absensi, entri indikator terpisah

**Status kerja**:
Keadaan pegawai pada satu tanggal: Bekerja, Libur, Izin, atau Sakit. Hanya Bekerja yang membutuhkan nilai indikator.
_Avoid_: nilai kosong sebagai tanda libur

**Nilai awal**:
Nilai yang dimasukkan penilai harian. Supervisor mengisi staf, sedangkan Manager mengisi Supervisor.
_Avoid_: skor akhir manual

**Koreksi Manager**:
Perubahan teraudit terhadap nilai awal atau status kerja staf sebelum lembar disetujui. Nilai awal tidak dihapus.
_Avoid_: edit diam-diam, approval opsional

**Nilai efektif**:
Nilai koreksi Manager bila tersedia, atau nilai awal bila tidak dikoreksi.

## Rekap

**KPI bulanan**:
Snapshot template, assignment penilai, dan hasil seorang pegawai untuk satu periode kalender.
_Avoid_: mengubah histori melalui edit template

**Aktual bulanan**:
SUM atau AVERAGE nilai efektif dari hari Bekerja yang sudah disetujui Manager.
_Avoid_: memasukkan hari nonkerja, menganggap nilai kosong sebagai nol

**Finalisasi**:
Pengesahan KPI bulanan per pegawai oleh Manager setelah periode berakhir dan seluruh lembar harian disetujui.
_Avoid_: finalisasi dini, finalisasi satu cabang sekaligus

**Hasil tanpa skor**:
Hasil final dengan alasan ketika pegawai tidak memiliki satu pun hari Bekerja dalam periode.

**Buka ulang**:
Tindakan Super Admin yang mengembalikan hasil final menjadi dapat diperbaiki, dengan alasan dan histori.
_Avoid_: Manager membuka hasilnya sendiri

## Konfigurasi Super Admin

**Versi template**:
Salinan indikator per jabatan yang bergerak dari DRAFT ke ACTIVE lalu RETIRED. Hanya DRAFT yang dapat diubah dan satu jabatan hanya boleh memiliki satu DRAFT serta satu ACTIVE.
_Avoid_: mengubah indikator aktif di tempat

**Skala predikat global**:
Lima predikat final dengan label dan nilai minimum yang berversi. Saat periode dibuka, skala aktif disalin ke KPI bulanan.
_Avoid_: membaca predikat terbaru untuk menghitung ulang periode lama

**Kesiapan jabatan**:
Jabatan aktif yang dinilai KPI dan dipakai pegawai aktif wajib memiliki versi template ACTIVE sebelum periode dapat dibuka.
_Avoid_: menghilangkan pegawai dari periode dengan menonaktifkan jabatan yang masih dipakai
