-- Perluasan jenis indikator (CHECKBOX, CATEGORY, SYSTEM, IMPORTED) dan agregasi (LATEST, COUNT).
-- Nilai enum baru tidak boleh dipakai dalam transaksi yang sama dengan ALTER TYPE ... ADD VALUE,
-- sehingga seluruh pernyataan di bawah ini hanya mendefinisikan tipe dan kolom, tanpa menulis data
-- yang memakai nilai baru tersebut.
ALTER TYPE "ValueKind" ADD VALUE IF NOT EXISTS 'CHECKBOX';
ALTER TYPE "ValueKind" ADD VALUE IF NOT EXISTS 'CATEGORY';
ALTER TYPE "ValueKind" ADD VALUE IF NOT EXISTS 'SYSTEM';
ALTER TYPE "ValueKind" ADD VALUE IF NOT EXISTS 'IMPORTED';

ALTER TYPE "AggregationType" ADD VALUE IF NOT EXISTS 'LATEST';
ALTER TYPE "AggregationType" ADD VALUE IF NOT EXISTS 'COUNT';

-- Status nilai harian, agar data kosong tidak pernah diperlakukan sebagai nol.
CREATE TYPE "ValueStatus" AS ENUM ('PENDING', 'AVAILABLE', 'NOT_APPLICABLE', 'MISSING');

-- CreateTable
CREATE TABLE "kpi_category_options" (
    "id" TEXT NOT NULL,
    "indicatorId" TEXT NOT NULL,
    "label" TEXT NOT NULL,
    "score" DECIMAL(5,2) NOT NULL,
    "sortOrder" INTEGER NOT NULL DEFAULT 1,
    "isActive" BOOLEAN NOT NULL DEFAULT true,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "kpi_category_options_pkey" PRIMARY KEY ("id")
);

-- CreateIndex
CREATE UNIQUE INDEX "kpi_category_options_indicatorId_sortOrder_key" ON "kpi_category_options"("indicatorId", "sortOrder");

-- CreateIndex
CREATE INDEX "kpi_category_options_indicatorId_isActive_idx" ON "kpi_category_options"("indicatorId", "isActive");

-- AddForeignKey
ALTER TABLE "kpi_category_options" ADD CONSTRAINT "kpi_category_options_indicatorId_fkey" FOREIGN KEY ("indicatorId") REFERENCES "kpi_indicators"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AlterTable
ALTER TABLE "monthly_kpi_items" ADD COLUMN "categoryOptionsSnapshot" JSONB;

-- AlterTable
ALTER TABLE "daily_values" ADD COLUMN "status" "ValueStatus" NOT NULL DEFAULT 'AVAILABLE',
ADD COLUMN "managerStatus" "ValueStatus",
ADD COLUMN "categoryOptionId" TEXT,
ADD COLUMN "managerCategoryOptionId" TEXT;

-- Backfill: baris lama tanpa nilai apa pun ditandai MISSING; baris yang sudah berisi nilai tetap AVAILABLE.
UPDATE "daily_values"
SET "status" = 'MISSING'
WHERE "enteredValue" IS NULL AND "managerValue" IS NULL AND "effectiveValue" IS NULL;
