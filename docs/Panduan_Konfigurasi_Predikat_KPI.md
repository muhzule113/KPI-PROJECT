# Panduan Konfigurasi Predikat KPI

Dokumen ini melengkapi panduan pengguna utama untuk konfigurasi KPI berbasis versi. Jika ada perbedaan kewenangan dengan panduan lama, ketentuan di dokumen ini yang berlaku.

## Kewenangan

- **Super Admin** mengelola skema rating, band predikat, indikator, template, dan rubrik.
- **Admin KPI** tetap mengelola periode, assignment penilai, konfigurasi import, monitoring, dan publikasi.
- **Supervisor** mengonfirmasi nilai KPI objektif, menilai KPI subjektif dengan satu predikat per KPI per hari, dan mengisi status kehadiran.

## Mengubah konfigurasi

Perubahan hanya boleh diterapkan melalui versi baru agar periode berjalan dan histori tidak berubah.

1. Buka konfigurasi yang aktif, lalu pilih **Salin versi aktif**.
2. Edit versi draft: label, nilai, rentang, warna, urutan, atau kriteria predikat.
3. Jalankan validasi dan perbaiki semua pesan kesalahan.
4. Aktifkan versi draft. Versi tersebut digunakan mulai periode berikutnya.

Skema penilaian Supervisor harus memiliki tepat lima kode internal berikut. Kode tidak dapat diganti atau dihapus setelah digunakan.

| Kode | Label default | Nilai default |
|---|---|---:|
| `POOR` | Perlu Perbaikan | 60% |
| `FAIR` | Cukup | 75% |
| `GOOD` | Baik | 85% |
| `VERY_GOOD` | Sangat Baik | 95% |
| `STAR` | Istimewa | 100% |

Aktivasi ditolak jika rentang memiliki gap atau overlap, nilai berada di luar 0–100, kode duplikat, atau salah satu dari lima pilihan manual belum lengkap.

## Penilaian harian Supervisor

- KPI objektif menampilkan nilai resmi dari servis, feedback pelanggan, komplain, work-log, stok, atau import. Nilainya tidak dapat diubah; pilih konfirmasi atau minta koreksi sumber.
- KPI subjektif menampilkan kriteria rubrik sebagai panduan. Pilih satu predikat untuk keseluruhan KPI.
- Catatan wajib diisi jika nilai predikat berada di bawah target indikator.
- KPI kehadiran tetap menggunakan status absensi.

KPI subjektif standar adalah `TEK-05`, `TEK-06`, `CS-02`, `ADM-06`, `KSR-05`, dan `GUD-06`. Nilai bulanan dihitung dari rata-rata penilaian harian. `CS-01` tetap berasal dari rating Pelayan pada feedback pelanggan; rating Teknisi tidak masuk ke KPI tersebut.
