-- Angka + predikat: ambang empat batas disimpan terpisah dari skor legacy.
-- Kolom score sengaja dipertahankan nullable agar snapshot/periode lama tetap dapat dibaca.
ALTER TABLE "kpi_category_options"
  ADD COLUMN "threshold" DECIMAL(18,4);

ALTER TABLE "kpi_category_options"
  ALTER COLUMN "score" DROP NOT NULL;

-- Konversi konfigurasi kategori lama ke lima ambang baku. Label/urutan lama tidak diubah.
UPDATE "kpi_category_options"
SET "threshold" = CASE "sortOrder"
  WHEN 1 THEN 60
  WHEN 2 THEN 70
  WHEN 3 THEN 80
  WHEN 4 THEN 90
  ELSE NULL
END
WHERE "threshold" IS NULL;

ALTER TABLE "kpi_category_options"
  ADD CONSTRAINT "kpi_category_options_threshold_nonnegative_ck"
  CHECK ("threshold" IS NULL OR "threshold" >= 0);

ALTER TABLE "kpi_category_options"
  ADD CONSTRAINT "kpi_category_options_threshold_precision_ck"
  CHECK ("threshold" IS NULL OR "threshold" <= 1000000000);
