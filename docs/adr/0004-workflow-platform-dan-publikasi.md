# Workflow KPI, platform, dan publikasi

Status: diterima, 7 September 2026. Menggantikan ADR 0002 dan memperjelas ADR 0003.

Staf dinilai harian oleh Supervisor. Hasil yang lengkap membentuk rekap untuk disahkan Manager yang ditugaskan; Manager mengembalikan bagian tertentu dengan alasan bila ada masalah. Manager tidak mengubah nilai harian staf. Tidak ada submit KPI oleh karyawan.

Manager yang ditugaskan menilai sekaligus memfinalisasi KPI Supervisor. Pengecualian pemisahan penilai–approver ini hanya untuk KPI Supervisor, sehingga satu Manager cukup; penilaian dan approval KPI diri sendiri tetap dilarang. KPI Supervisor menunggu hasil tim yang disahkan, sedangkan staf tidak menunggu hasil Supervisor.

Admin KPI mempublikasikan setelah seluruh KPI approved. Skor/predikat milik sendiri baru terlihat setelah publikasi; penguncian merupakan langkah terpisah. Koreksi hasil final memakai permintaan dan persetujuan pihak lain beserta audit. Sumber yang berubah sebelum final membatalkan review bagian terdampak.

CapabilityMatrix menentukan platform dan tindakan. Teknisi/Pelayan/Gudang memakai mobile; Kasir/Admin Operasional/Supervisor/Manager memakai kedua platform; Admin KPI/Admin Sistem/Auditor memakai web. API menerbitkan token mobile dengan kanal server dan memeriksa ulang akun/profil/role setiap request. Hak administratif tidak memberikan kewenangan transaksi.

Snapshot cabang/jabatan/penilai menjadi rujukan cakupan data. Auditor membaca lintas cabang, Manager/Supervisor sesuai assignment, dan karyawan membaca KPI sendiri termasuk histori. Daftar, detail, dashboard, ekspor, evidence dan notifikasi memakai cakupan yang sama. Skor internal tim tersedia bagi penilai yang berwenang untuk penilaian; hasil KPI sendiri tetap menunggu publikasi.

Persiapan harian dijalankan melalui scheduler dengan identitas unik item–tanggal. Kegagalan tercatat di audit dan dapat diulang. Fakta belum tersedia tetap kosong/UNSCORABLE; engine, rumus dan bobot mengikuti snapshot.
