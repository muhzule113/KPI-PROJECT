# ADR 0005 — Katalog Master KPI dari Referensi WhatsApp

- Status: diterima
- Tanggal: 2026-09-10

## Konteks

Tujuh gambar WhatsApp mendefinisikan enam template KPI untuk Teknisi, Pelayan, Admin Operasional, Kasir, Gudang/Sparepart, dan Supervisor. Konfigurasi aplikasi sebelumnya masih memakai tiga indikator generik pada beberapa jabatan dan rating 1–5 pada indikator observasi.

## Keputusan

- Katalog baku berisi 39 indikator `NUMERIC` sesuai bagian 6 PRD. Persentase diisi langsung 0–100 dan memakai `AVERAGE`; jumlah unit, komplain, dan rupiah memakai `SUM`.
- TEK-01 menargetkan 80 unit. TEK-03 menargetkan 3% dan gagal pada 6%; CS-05 menargetkan 3 komplain dan gagal pada 5; GUD-02 menargetkan 2% dan gagal pada 5%.
- KSR-02 memakai `ZERO_TOLERANCE`: Rp0 mendapat 100% dan setiap selisih mendapat 0%.
- Target SUP-01 sampai SUP-07 adalah 90%, 95%, 95%, 90%, 100%, 95%, dan 100%.
- Sinkronisasi membuat atau memakai ulang draft yang masih identik dengan versi aktif, lalu mengaktifkan versi baru dalam satu transaksi. Draft yang telah diedit pengguna tidak ditimpa.
- Versi baru hanya menjadi sumber snapshot periode yang dibuka setelah aktivasi. Snapshot Agustus dan September 2026 dipertahankan.
- Nilai tetap memakai alur penilaian harian yang ada; integrasi tiket, absensi, komplain, kas, atau stok tidak termasuk keputusan ini.
- Crew dan Kurir tetap tersedia dengan template terpisah. Manager bukan subjek KPI. Admin Operasional berbeda dari role Super Admin.

## Konsekuensi

Super Admin melihat operator target yang eksplisit, input persentase dibatasi 0–100, dan histori periode tidak berubah saat master diperbarui. Perubahan sumber nilai operasional memerlukan keputusan dan implementasi terpisah.
