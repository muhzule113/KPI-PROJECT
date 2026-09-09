# Panduan Konfigurasi Predikat KPI

Panduan ini ditujukan untuk **Super Admin** yang ingin mengubah nama, nilai, rentang, warna, atau urutan predikat KPI. Perubahan selalu dibuat sebagai draft agar periode yang sudah disiapkan dan histori tetap aman.

## Cara mengubah predikat

1. Buka **Administrasi KPI > Pusat Administrasi KPI**.
2. Pilih **Ubah predikat**.
3. Pilih **Lanjutkan draft** jika draft sudah ada. Jika belum, pilih **Buat draft dari skema aktif**.
4. Ubah data lima predikat, lalu pilih **Simpan dan lanjut**.
5. Periksa pesan kesalahan dan pilih template jabatan yang akan memakai predikat baru.
6. Pilih **Aktifkan untuk periode berikutnya**.

Jika template jabatan yang dipilih masih memiliki draft lain, selesaikan atau hapus draft tersebut sebelum aktivasi.

## Arti setiap kolom

| Kolom | Kegunaan |
|---|---|
| **Label** | Nama predikat yang terlihat oleh pengguna. |
| **Nilai pilihan** | Nilai harian saat Supervisor memilih predikat untuk KPI subjektif. |
| **Rentang awal dan akhir** | Rentang skor akhir KPI yang menghasilkan predikat tersebut. |
| **Warna** | Warna penanda predikat pada tampilan. |
| **Urutan** | Posisi predikat saat ditampilkan, dari 1 sampai 5. |

**Nilai pilihan berbeda dari rentang skor.** Contohnya, Supervisor memilih **Sangat Baik** sehingga nilai hariannya 95. Sementara itu, skor akhir 92 mendapat predikat **Sangat Baik** karena berada pada rentang 90–94,99.

## Nilai bawaan

Lima kode internal berikut bersifat tetap. Kode tidak dapat diubah, ditambah, atau dihapus melalui wizard.

| Urutan | Kode | Label | Nilai pilihan | Rentang skor akhir |
|---:|---|---|---:|---:|
| 1 | `STAR` | Istimewa | 100 | 95–100 |
| 2 | `VERY_GOOD` | Sangat Baik | 95 | 90–94,99 |
| 3 | `GOOD` | Baik | 85 | 80–89,99 |
| 4 | `FAIR` | Cukup | 75 | 70–79,99 |
| 5 | `POOR` | Perlu Perbaikan | 60 | 0–69,99 |

## Syarat agar dapat diaktifkan

- Semua nilai dan rentang harus berada pada 0–100.
- Nilai pilihan dan nomor urutan harus berbeda untuk setiap predikat.
- Rentang harus menutup seluruh skor 0–100 tanpa celah atau tumpang tindih.
- Minimal satu template jabatan staf harus dipilih.
- Template yang dipilih tidak boleh memiliki draft lain.

Template Supervisor tidak tersedia di wizard ini dan hanya dapat diatur melalui **Mode Lanjutan**.

## Dampak aktivasi

- Sistem membuat versi baru untuk setiap template jabatan yang dipilih.
- Predikat baru dipakai pada periode yang disiapkan setelah aktivasi.
- Periode berstatus **READY** atau **OPEN** serta histori tetap memakai snapshot lama.
- Template jabatan yang tidak dipilih tetap memakai skema sebelumnya.

Untuk mengubah kriteria panduan penilaian subjektif, gunakan **Pusat Administrasi KPI > Atur KPI jabatan**. Kriteria rubrik bukan bagian dari konfigurasi predikat.
