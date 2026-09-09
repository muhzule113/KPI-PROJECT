-- Version template KPI instead of mutating the configuration used by future periods.
CREATE TYPE "VersionStatus" AS ENUM ('DRAFT', 'ACTIVE', 'RETIRED');

CREATE TABLE "kpi_template_versions" (
    "id" TEXT NOT NULL,
    "templateId" TEXT NOT NULL,
    "versionNumber" INTEGER NOT NULL,
    "status" "VersionStatus" NOT NULL DEFAULT 'DRAFT',
    "activatedAt" TIMESTAMP(3),
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,
    CONSTRAINT "kpi_template_versions_pkey" PRIMARY KEY ("id")
);

INSERT INTO "kpi_templates" ("id", "positionId", "name", "isActive", "createdAt", "updatedAt")
SELECT 'template-' || position."id", position."id", 'KPI ' || position."name", false, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM "positions" AS position
WHERE position."isKpiSubject" = true
  AND NOT EXISTS (SELECT 1 FROM "kpi_templates" AS template WHERE template."positionId" = position."id");

INSERT INTO "kpi_template_versions" (
    "id", "templateId", "versionNumber", "status", "activatedAt", "createdAt", "updatedAt"
)
SELECT
    'version-' || "id",
    "id",
    1,
    CASE WHEN "isActive" THEN 'ACTIVE'::"VersionStatus" ELSE 'DRAFT'::"VersionStatus" END,
    CASE WHEN "isActive" THEN "updatedAt" ELSE NULL END,
    "createdAt",
    "updatedAt"
FROM "kpi_templates";

ALTER TABLE "kpi_indicators" ADD COLUMN "templateVersionId" TEXT;

UPDATE "kpi_indicators" AS indicator
SET "templateVersionId" = version."id"
FROM "kpi_template_versions" AS version
WHERE version."templateId" = indicator."templateId";

ALTER TABLE "kpi_indicators" ALTER COLUMN "templateVersionId" SET NOT NULL;
ALTER TABLE "kpi_indicators" DROP CONSTRAINT "kpi_indicators_templateId_fkey";
DROP INDEX "kpi_indicators_templateId_sortOrder_idx";
DROP INDEX "kpi_indicators_templateId_code_key";
ALTER TABLE "kpi_indicators" DROP COLUMN "templateId";
ALTER TABLE "kpi_templates" DROP COLUMN "isActive";

CREATE UNIQUE INDEX "kpi_template_versions_templateId_versionNumber_key"
    ON "kpi_template_versions"("templateId", "versionNumber");
CREATE INDEX "kpi_template_versions_templateId_status_idx"
    ON "kpi_template_versions"("templateId", "status");
CREATE UNIQUE INDEX "kpi_template_versions_one_draft_idx"
    ON "kpi_template_versions"("templateId") WHERE "status" = 'DRAFT';
CREATE UNIQUE INDEX "kpi_template_versions_one_active_idx"
    ON "kpi_template_versions"("templateId") WHERE "status" = 'ACTIVE';
CREATE INDEX "kpi_indicators_templateVersionId_sortOrder_idx"
    ON "kpi_indicators"("templateVersionId", "sortOrder");
CREATE UNIQUE INDEX "kpi_indicators_templateVersionId_code_key"
    ON "kpi_indicators"("templateVersionId", "code");

ALTER TABLE "kpi_template_versions"
    ADD CONSTRAINT "kpi_template_versions_templateId_fkey"
    FOREIGN KEY ("templateId") REFERENCES "kpi_templates"("id") ON DELETE CASCADE ON UPDATE CASCADE;
ALTER TABLE "kpi_indicators"
    ADD CONSTRAINT "kpi_indicators_templateVersionId_fkey"
    FOREIGN KEY ("templateVersionId") REFERENCES "kpi_template_versions"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- A scheme row is one immutable version of the single global predicate scale.
CREATE TABLE "kpi_rating_schemes" (
    "id" TEXT NOT NULL,
    "version" INTEGER NOT NULL,
    "status" "VersionStatus" NOT NULL DEFAULT 'DRAFT',
    "activatedAt" TIMESTAMP(3),
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,
    CONSTRAINT "kpi_rating_schemes_pkey" PRIMARY KEY ("id")
);

CREATE TABLE "kpi_rating_bands" (
    "id" TEXT NOT NULL,
    "ratingSchemeId" TEXT NOT NULL,
    "code" TEXT NOT NULL,
    "label" TEXT NOT NULL,
    "minScore" DECIMAL(5,2) NOT NULL,
    "sortOrder" INTEGER NOT NULL,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,
    CONSTRAINT "kpi_rating_bands_pkey" PRIMARY KEY ("id"),
    CONSTRAINT "kpi_rating_bands_values_check" CHECK ("minScore" >= 0 AND "minScore" <= 100 AND "sortOrder" BETWEEN 1 AND 5)
);

CREATE UNIQUE INDEX "kpi_rating_schemes_version_key" ON "kpi_rating_schemes"("version");
CREATE INDEX "kpi_rating_schemes_status_idx" ON "kpi_rating_schemes"("status");
CREATE UNIQUE INDEX "kpi_rating_schemes_one_draft_idx"
    ON "kpi_rating_schemes"((1)) WHERE "status" = 'DRAFT';
CREATE UNIQUE INDEX "kpi_rating_schemes_one_active_idx"
    ON "kpi_rating_schemes"((1)) WHERE "status" = 'ACTIVE';
CREATE UNIQUE INDEX "kpi_rating_bands_ratingSchemeId_code_key"
    ON "kpi_rating_bands"("ratingSchemeId", "code");
CREATE UNIQUE INDEX "kpi_rating_bands_ratingSchemeId_sortOrder_key"
    ON "kpi_rating_bands"("ratingSchemeId", "sortOrder");

ALTER TABLE "kpi_rating_bands"
    ADD CONSTRAINT "kpi_rating_bands_ratingSchemeId_fkey"
    FOREIGN KEY ("ratingSchemeId") REFERENCES "kpi_rating_schemes"("id") ON DELETE CASCADE ON UPDATE CASCADE;

INSERT INTO "kpi_rating_schemes" (
    "id", "version", "status", "activatedAt", "createdAt", "updatedAt"
) VALUES (
    'global-rating-v1', 1, 'ACTIVE', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
);

INSERT INTO "kpi_rating_bands" (
    "id", "ratingSchemeId", "code", "label", "minScore", "sortOrder", "createdAt", "updatedAt"
) VALUES
    ('global-rating-v1-poor', 'global-rating-v1', 'POOR', 'Perlu Perbaikan', 0, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
    ('global-rating-v1-fair', 'global-rating-v1', 'FAIR', 'Cukup', 70, 2, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
    ('global-rating-v1-good', 'global-rating-v1', 'GOOD', 'Baik', 80, 3, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
    ('global-rating-v1-very-good', 'global-rating-v1', 'VERY_GOOD', 'Sangat Baik', 90, 4, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
    ('global-rating-v1-star', 'global-rating-v1', 'STAR', 'Istimewa', 95, 5, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP);

-- Existing periods retain the four predicate thresholds used before this migration.
ALTER TABLE "monthly_kpis" ADD COLUMN "ratingBandsSnapshot" JSONB;
UPDATE "monthly_kpis"
SET "ratingBandsSnapshot" = '[
  {"code":"NEEDS_IMPROVEMENT","label":"Perlu Perbaikan","minScore":"0","sortOrder":1},
  {"code":"FAIR","label":"Cukup","minScore":"70","sortOrder":2},
  {"code":"GOOD","label":"Baik","minScore":"80","sortOrder":3},
  {"code":"VERY_GOOD","label":"Sangat Baik","minScore":"90","sortOrder":4}
]'::jsonb;
ALTER TABLE "monthly_kpis" ALTER COLUMN "ratingBandsSnapshot" SET NOT NULL;
