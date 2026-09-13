-- Setiap periode DRAFT dapat memilih versi template KPI per jabatan.
-- Relasi ini dikunci secara fungsional saat periode dibuka; snapshot bulanan
-- tetap menjadi sumber kebenaran untuk periode OPEN/COMPLETED.
CREATE TABLE "kpi_period_template_selections" (
    "id" TEXT NOT NULL,
    "periodId" TEXT NOT NULL,
    "positionId" TEXT NOT NULL,
    "templateVersionId" TEXT NOT NULL,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "kpi_period_template_selections_pkey" PRIMARY KEY ("id")
);

CREATE UNIQUE INDEX "kpi_period_template_selections_periodId_positionId_key"
  ON "kpi_period_template_selections"("periodId", "positionId");

CREATE INDEX "kpi_period_template_selections_templateVersionId_idx"
  ON "kpi_period_template_selections"("templateVersionId");

ALTER TABLE "kpi_period_template_selections"
  ADD CONSTRAINT "kpi_period_template_selections_periodId_fkey"
  FOREIGN KEY ("periodId") REFERENCES "kpi_periods"("id") ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE "kpi_period_template_selections"
  ADD CONSTRAINT "kpi_period_template_selections_positionId_fkey"
  FOREIGN KEY ("positionId") REFERENCES "positions"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE "kpi_period_template_selections"
  ADD CONSTRAINT "kpi_period_template_selections_templateVersionId_fkey"
  FOREIGN KEY ("templateVersionId") REFERENCES "kpi_template_versions"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- Periode DRAFT yang sudah dibuat sebelum fitur ini mendapat default versi aktif.
-- Periode OPEN/COMPLETED sengaja tidak disentuh karena snapshotnya immutable.
INSERT INTO "kpi_period_template_selections" ("id", "periodId", "positionId", "templateVersionId", "updatedAt")
SELECT
  'period-template-' || period."id" || '-' || position."id",
  period."id",
  position."id",
  active_version."id",
  CURRENT_TIMESTAMP
FROM "kpi_periods" period
JOIN "positions" position
  ON position."isActive" = true
 AND position."isKpiSubject" = true
JOIN "kpi_templates" template
  ON template."positionId" = position."id"
JOIN LATERAL (
  SELECT version."id"
  FROM "kpi_template_versions" version
  WHERE version."templateId" = template."id"
    AND version."status" = 'ACTIVE'
  ORDER BY version."versionNumber" DESC
  LIMIT 1
) active_version ON true
WHERE period."status" = 'DRAFT'
ON CONFLICT ("periodId", "positionId") DO NOTHING;
