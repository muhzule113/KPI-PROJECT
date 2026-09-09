-- Keep critical numeric and date invariants valid even when data is written outside the application.
ALTER TABLE "employees"
  ADD CONSTRAINT "employees_employment_dates_check"
  CHECK ("endedAt" IS NULL OR "endedAt" >= "joinedAt");

ALTER TABLE "kpi_periods"
  ADD CONSTRAINT "kpi_periods_calendar_check"
  CHECK ("year" BETWEEN 2020 AND 2100 AND "month" BETWEEN 1 AND 12 AND "endDate" >= "startDate");

ALTER TABLE "kpi_indicators"
  ADD CONSTRAINT "kpi_indicators_values_check"
  CHECK (
    "weight" > 0 AND "weight" <= 100 AND "sortOrder" >= 1
    AND ("kind" <> 'RATING' OR ("target" BETWEEN 1 AND 5 AND "aggregation" = 'AVERAGE' AND "direction" = 'HIGHER'))
    AND ("direction" <> 'HIGHER' OR "target" > 0)
    AND ("direction" <> 'LOWER' OR ("failureLimit" IS NOT NULL AND "failureLimit" > "target"))
  );

ALTER TABLE "monthly_kpi_items"
  ADD CONSTRAINT "monthly_kpi_items_snapshot_check"
  CHECK ("weightSnapshot" > 0 AND "weightSnapshot" <= 100 AND "sortOrderSnapshot" >= 1);

ALTER TABLE "monthly_kpis"
  ADD CONSTRAINT "monthly_kpis_score_version_check"
  CHECK (
    ("finalScore" IS NULL OR ("finalScore" >= 0 AND "finalScore" <= 100))
    AND "rowVersion" > 0 AND "revisionNumber" >= 0
    AND "subjectRoleSnapshot" IN ('SUPERVISOR', 'EMPLOYEE')
  );

ALTER TABLE "daily_sheets"
  ADD CONSTRAINT "daily_sheets_row_version_check"
  CHECK ("rowVersion" > 0);

ALTER TABLE "daily_values"
  ADD CONSTRAINT "daily_values_non_negative_check"
  CHECK (
    ("enteredValue" IS NULL OR ("enteredValue" >= 0 AND "enteredValue" <= 1000000000))
    AND ("managerValue" IS NULL OR ("managerValue" >= 0 AND "managerValue" <= 1000000000))
    AND ("effectiveValue" IS NULL OR ("effectiveValue" >= 0 AND "effectiveValue" <= 1000000000))
  );

ALTER TABLE "evidence"
  ADD CONSTRAINT "evidence_size_hash_check"
  CHECK ("fileSize" > 0 AND "fileSize" <= 10485760 AND "sha256Hash" ~ '^[0-9a-f]{64}$');
