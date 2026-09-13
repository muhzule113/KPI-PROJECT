-- Konfigurasi kategori baru hanya menyimpan batas angka (threshold).
-- Snapshot JSON periode lama tetap mempertahankan properti score sehingga masih
-- dapat dibaca oleh resolver legacy tanpa mengubah perhitungan periode tersebut.
ALTER TABLE "kpi_category_options"
  DROP COLUMN "score";
