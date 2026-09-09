# Arah Desain KPI Harian

## Design Read

Aplikasi operasional KPI dengan bahasa visual **signal console**: gelap, fokus, padat, dan terasa seperti perangkat kerja. Referensi diterjemahkan ke kebutuhan toko, bukan menyalin struktur kalendernya. Dial: `ENERGY 1 / RHYTHM 2 / MOTION 1`.

## Token dan alasan

- Kanvas `#07110E` dan chrome `#020806`: membangun suasana console tanpa memakai hitam murni.
- Permukaan `#101D19`, `#172824`, dan `#243B34`: lapisan emerald membedakan konteks, panel, dan pilihan tanpa shadow kartu generik.
- Ink `#EDF5F0`, graphite `#CBD8D1`, dan muted `#8FA39A`: hierarki tetap terbaca pada layar gelap.
- Lime `#D8FF3F`: hanya untuk aksi utama, fokus, dan posisi aktif; bukan warna isi setiap panel.
- Warna status tetap semantik dan selalu disertai ikon serta teks.
- Garis `#263A33` / `#405A50`: membentuk struktur tabel dan kontrol secara halus.

## Tipografi dan ritme

- `Bricolage Grotesque` variable tetap di-self-host; bobot utama diturunkan agar mendekati karakter tipis pada referensi.
- Angka memakai tabular figures untuk perbandingan KPI.
- Judul halaman dibatasi 1.8-2.75rem; tidak ada lagi headline bergaya poster.
- Ritme 4/8 px dengan section gap 22-28 px menjaga layar data tetap rapat namun tidak sesak.

## Layout dan panel

- Desktop memakai chrome bar tunggal, tab konteks halaman, dan sidebar 232 px dengan ikon serta label agar tujuan menu langsung terbaca tanpa menebak ikon.
- Mobile mempertahankan tiga tujuan utama plus Menu dan bottom navigation.
- Dashboard memakai satu panel fokus berlapis, ledger metrik, dan alat kerja dua kolom; data tetap nyata.
- Form, filter, tab, kontrol input, dan tabel mempertahankan perilaku bisnis, dengan permukaan hijau gelap dan header tabel sticky.
- Radius 8/11/14/16 px membedakan kontrol, pilihan, panel, dan hero.
- Elevasi hanya pada panel yang benar-benar mengambang: menu akun, auth, hero, dan state khusus.

## Pola interaksi form

- Dropdown memakai popup gelap buatan aplikasi agar warna, fokus keyboard, typeahead, dan ukuran target tetap konsisten lintas sistem operasi.
- Pemilih tanggal memakai kalender modal berbahasa Indonesia dengan nilai formulir tetap `YYYY-MM-DD` dan acuan zona waktu Asia/Makassar.
- Tambah dan edit data ringkas ditempatkan dalam modal; akun dan indikator memakai dua langkah agar bidang identitas tidak bercampur dengan penempatan atau perhitungan.
- Aksi berisiko memakai dialog konfirmasi. Keberhasilan tindakan kecil tampil sebagai toast maksimum empat detik, sedangkan error tetap dekat dengan form agar mudah diperbaiki.
- Desktop memakai dialog terpusat; layar kecil memakai bottom sheet dengan body yang dapat digulir dan tombol aksi yang tetap terlihat.

## Ilustrasi dan motion

- SVG lokal tetap memakai ponsel, checklist, kalender, service ticket, dan alat servis, tetapi dirender seperti diagram instrumentasi.
- Ambient radial light memberi kedalaman seperti referensi; tidak ada stock art atau aset CDN.
- Motion 140-180 ms hanya untuk hover, pressed, drawer, disclosure, pilihan, loading, dan perubahan state.
- `prefers-reduced-motion`, target sentuh 44 px, skip-link, focus ring, serta status teks+ikon dipertahankan.

Tema dark-only dipilih mengikuti arah visual klien dan konteks console kerja yang ditunjukkan pada referensi.
