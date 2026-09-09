-- CreateEnum
CREATE TYPE "UserRole" AS ENUM ('ADMIN', 'MANAGER', 'SUPERVISOR', 'EMPLOYEE');

-- CreateEnum
CREATE TYPE "EmployeeStatus" AS ENUM ('ACTIVE', 'INACTIVE', 'RESIGNED');

-- CreateEnum
CREATE TYPE "PeriodStatus" AS ENUM ('DRAFT', 'OPEN', 'COMPLETED');

-- CreateEnum
CREATE TYPE "MonthlyKpiStatus" AS ENUM ('IN_PROGRESS', 'READY', 'FINALIZED', 'REOPENED');

-- CreateEnum
CREATE TYPE "DailySheetStatus" AS ENUM ('PENDING', 'DRAFT', 'SUBMITTED', 'REVISION_REQUIRED', 'APPROVED');

-- CreateEnum
CREATE TYPE "WorkStatus" AS ENUM ('WORKED', 'OFF', 'PERMIT', 'SICK');

-- CreateEnum
CREATE TYPE "ValueKind" AS ENUM ('NUMERIC', 'RATING');

-- CreateEnum
CREATE TYPE "AggregationType" AS ENUM ('SUM', 'AVERAGE');

-- CreateEnum
CREATE TYPE "KpiDirection" AS ENUM ('HIGHER', 'LOWER', 'ZERO_TOLERANCE');

-- CreateEnum
CREATE TYPE "CalculationStatus" AS ENUM ('PENDING', 'CALCULATED', 'UNSCORABLE');

-- CreateTable
CREATE TABLE "users" (
    "id" TEXT NOT NULL,
    "name" TEXT NOT NULL,
    "email" TEXT NOT NULL,
    "emailVerified" BOOLEAN NOT NULL DEFAULT false,
    "image" TEXT,
    "isActive" BOOLEAN NOT NULL DEFAULT true,
    "role" "UserRole" NOT NULL DEFAULT 'EMPLOYEE',
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "users_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "sessions" (
    "id" TEXT NOT NULL,
    "expiresAt" TIMESTAMP(3) NOT NULL,
    "token" TEXT NOT NULL,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,
    "ipAddress" TEXT,
    "userAgent" TEXT,
    "userId" TEXT NOT NULL,

    CONSTRAINT "sessions_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "accounts" (
    "id" TEXT NOT NULL,
    "accountId" TEXT NOT NULL,
    "providerId" TEXT NOT NULL,
    "userId" TEXT NOT NULL,
    "accessToken" TEXT,
    "refreshToken" TEXT,
    "idToken" TEXT,
    "accessTokenExpiresAt" TIMESTAMP(3),
    "refreshTokenExpiresAt" TIMESTAMP(3),
    "scope" TEXT,
    "password" TEXT,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "accounts_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "verifications" (
    "id" TEXT NOT NULL,
    "identifier" TEXT NOT NULL,
    "value" TEXT NOT NULL,
    "expiresAt" TIMESTAMP(3) NOT NULL,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "verifications_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "branches" (
    "id" TEXT NOT NULL,
    "code" TEXT NOT NULL,
    "name" TEXT NOT NULL,
    "isActive" BOOLEAN NOT NULL DEFAULT true,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "branches_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "positions" (
    "id" TEXT NOT NULL,
    "code" TEXT NOT NULL,
    "name" TEXT NOT NULL,
    "isKpiSubject" BOOLEAN NOT NULL DEFAULT true,
    "isActive" BOOLEAN NOT NULL DEFAULT true,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "positions_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "employees" (
    "id" TEXT NOT NULL,
    "userId" TEXT NOT NULL,
    "employeeNumber" TEXT NOT NULL,
    "name" TEXT NOT NULL,
    "email" TEXT NOT NULL,
    "branchId" TEXT NOT NULL,
    "positionId" TEXT NOT NULL,
    "supervisorId" TEXT,
    "managerId" TEXT,
    "joinedAt" DATE NOT NULL,
    "endedAt" DATE,
    "status" "EmployeeStatus" NOT NULL DEFAULT 'ACTIVE',
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "employees_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "kpi_templates" (
    "id" TEXT NOT NULL,
    "positionId" TEXT NOT NULL,
    "name" TEXT NOT NULL,
    "isActive" BOOLEAN NOT NULL DEFAULT false,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "kpi_templates_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "kpi_indicators" (
    "id" TEXT NOT NULL,
    "templateId" TEXT NOT NULL,
    "code" TEXT NOT NULL,
    "name" TEXT NOT NULL,
    "description" TEXT,
    "kind" "ValueKind" NOT NULL,
    "unit" TEXT NOT NULL,
    "aggregation" "AggregationType" NOT NULL,
    "direction" "KpiDirection" NOT NULL,
    "target" DECIMAL(18,4) NOT NULL,
    "failureLimit" DECIMAL(18,4),
    "weight" DECIMAL(5,2) NOT NULL,
    "sortOrder" INTEGER NOT NULL DEFAULT 1,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "kpi_indicators_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "kpi_periods" (
    "id" TEXT NOT NULL,
    "name" TEXT NOT NULL,
    "year" INTEGER NOT NULL,
    "month" INTEGER NOT NULL,
    "startDate" DATE NOT NULL,
    "endDate" DATE NOT NULL,
    "status" "PeriodStatus" NOT NULL DEFAULT 'DRAFT',
    "createdById" TEXT NOT NULL,
    "openedAt" TIMESTAMP(3),
    "completedAt" TIMESTAMP(3),
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "kpi_periods_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "monthly_kpis" (
    "id" TEXT NOT NULL,
    "periodId" TEXT NOT NULL,
    "employeeId" TEXT NOT NULL,
    "employeeNumberSnapshot" TEXT NOT NULL,
    "employeeNameSnapshot" TEXT NOT NULL,
    "subjectRoleSnapshot" "UserRole" NOT NULL,
    "branchIdSnapshot" TEXT NOT NULL,
    "branchNameSnapshot" TEXT NOT NULL,
    "positionIdSnapshot" TEXT NOT NULL,
    "positionCodeSnapshot" TEXT NOT NULL,
    "positionNameSnapshot" TEXT NOT NULL,
    "supervisorIdSnapshot" TEXT,
    "managerIdSnapshot" TEXT NOT NULL,
    "status" "MonthlyKpiStatus" NOT NULL DEFAULT 'IN_PROGRESS',
    "finalScore" DECIMAL(8,2),
    "ratingCode" TEXT,
    "ratingLabel" TEXT,
    "noScoreReason" TEXT,
    "rowVersion" INTEGER NOT NULL DEFAULT 1,
    "finalizedById" TEXT,
    "finalizedAt" TIMESTAMP(3),
    "reopenedById" TEXT,
    "reopenedAt" TIMESTAMP(3),
    "reopenReason" TEXT,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "monthly_kpis_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "monthly_kpi_items" (
    "id" TEXT NOT NULL,
    "monthlyKpiId" TEXT NOT NULL,
    "codeSnapshot" TEXT NOT NULL,
    "nameSnapshot" TEXT NOT NULL,
    "descriptionSnapshot" TEXT,
    "kindSnapshot" "ValueKind" NOT NULL,
    "unitSnapshot" TEXT NOT NULL,
    "aggregationSnapshot" "AggregationType" NOT NULL,
    "directionSnapshot" "KpiDirection" NOT NULL,
    "targetSnapshot" DECIMAL(18,4) NOT NULL,
    "failureLimitSnapshot" DECIMAL(18,4),
    "weightSnapshot" DECIMAL(5,2) NOT NULL,
    "sortOrderSnapshot" INTEGER NOT NULL,
    "actual" DECIMAL(18,2),
    "achievementPercentage" DECIMAL(8,2),
    "weightedScore" DECIMAL(8,2),
    "calculationStatus" "CalculationStatus" NOT NULL DEFAULT 'PENDING',
    "calculationNote" TEXT,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "monthly_kpi_items_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "daily_sheets" (
    "id" TEXT NOT NULL,
    "monthlyKpiId" TEXT NOT NULL,
    "entryDate" DATE NOT NULL,
    "workStatus" "WorkStatus",
    "status" "DailySheetStatus" NOT NULL DEFAULT 'PENDING',
    "note" TEXT,
    "enteredById" TEXT,
    "submittedAt" TIMESTAMP(3),
    "managerReviewedById" TEXT,
    "managerReviewedAt" TIMESTAMP(3),
    "managerReason" TEXT,
    "rowVersion" INTEGER NOT NULL DEFAULT 1,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "daily_sheets_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "daily_values" (
    "id" TEXT NOT NULL,
    "dailySheetId" TEXT NOT NULL,
    "monthlyKpiItemId" TEXT NOT NULL,
    "enteredValue" DECIMAL(18,4),
    "managerValue" DECIMAL(18,4),
    "effectiveValue" DECIMAL(18,4),
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "daily_values_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "evidence" (
    "id" TEXT NOT NULL,
    "dailySheetId" TEXT NOT NULL,
    "fileName" TEXT NOT NULL,
    "storagePath" TEXT NOT NULL,
    "mimeType" TEXT NOT NULL,
    "fileSize" BIGINT NOT NULL,
    "sha256Hash" TEXT NOT NULL,
    "scanStatus" TEXT NOT NULL DEFAULT 'pending',
    "scanNote" TEXT,
    "uploadedById" TEXT NOT NULL,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "evidence_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "notifications" (
    "id" TEXT NOT NULL,
    "userId" TEXT NOT NULL,
    "title" TEXT NOT NULL,
    "body" TEXT NOT NULL,
    "type" TEXT NOT NULL,
    "actionUrl" TEXT,
    "readAt" TIMESTAMP(3),
    "dedupeKey" TEXT NOT NULL,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "notifications_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "audit_events" (
    "id" TEXT NOT NULL,
    "actorId" TEXT,
    "action" TEXT NOT NULL,
    "subjectType" TEXT NOT NULL,
    "subjectId" TEXT NOT NULL,
    "beforeJson" JSONB,
    "afterJson" JSONB,
    "reason" TEXT,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "audit_events_pkey" PRIMARY KEY ("id")
);

-- CreateIndex
CREATE UNIQUE INDEX "users_email_key" ON "users"("email");

-- CreateIndex
CREATE UNIQUE INDEX "sessions_token_key" ON "sessions"("token");

-- CreateIndex
CREATE INDEX "sessions_userId_idx" ON "sessions"("userId");

-- CreateIndex
CREATE INDEX "accounts_userId_idx" ON "accounts"("userId");

-- CreateIndex
CREATE UNIQUE INDEX "accounts_providerId_accountId_key" ON "accounts"("providerId", "accountId");

-- CreateIndex
CREATE INDEX "verifications_identifier_idx" ON "verifications"("identifier");

-- CreateIndex
CREATE UNIQUE INDEX "branches_code_key" ON "branches"("code");

-- CreateIndex
CREATE UNIQUE INDEX "positions_code_key" ON "positions"("code");

-- CreateIndex
CREATE UNIQUE INDEX "employees_userId_key" ON "employees"("userId");

-- CreateIndex
CREATE UNIQUE INDEX "employees_employeeNumber_key" ON "employees"("employeeNumber");

-- CreateIndex
CREATE UNIQUE INDEX "employees_email_key" ON "employees"("email");

-- CreateIndex
CREATE INDEX "employees_branchId_positionId_status_idx" ON "employees"("branchId", "positionId", "status");

-- CreateIndex
CREATE INDEX "employees_supervisorId_status_idx" ON "employees"("supervisorId", "status");

-- CreateIndex
CREATE INDEX "employees_managerId_status_idx" ON "employees"("managerId", "status");

-- CreateIndex
CREATE UNIQUE INDEX "kpi_templates_positionId_key" ON "kpi_templates"("positionId");

-- CreateIndex
CREATE INDEX "kpi_indicators_templateId_sortOrder_idx" ON "kpi_indicators"("templateId", "sortOrder");

-- CreateIndex
CREATE UNIQUE INDEX "kpi_indicators_templateId_code_key" ON "kpi_indicators"("templateId", "code");

-- CreateIndex
CREATE INDEX "kpi_periods_status_idx" ON "kpi_periods"("status");

-- CreateIndex
CREATE UNIQUE INDEX "kpi_periods_year_month_key" ON "kpi_periods"("year", "month");

-- CreateIndex
CREATE INDEX "monthly_kpis_managerIdSnapshot_status_idx" ON "monthly_kpis"("managerIdSnapshot", "status");

-- CreateIndex
CREATE INDEX "monthly_kpis_supervisorIdSnapshot_status_idx" ON "monthly_kpis"("supervisorIdSnapshot", "status");

-- CreateIndex
CREATE UNIQUE INDEX "monthly_kpis_periodId_employeeId_key" ON "monthly_kpis"("periodId", "employeeId");

-- CreateIndex
CREATE INDEX "monthly_kpi_items_monthlyKpiId_sortOrderSnapshot_idx" ON "monthly_kpi_items"("monthlyKpiId", "sortOrderSnapshot");

-- CreateIndex
CREATE UNIQUE INDEX "monthly_kpi_items_monthlyKpiId_codeSnapshot_key" ON "monthly_kpi_items"("monthlyKpiId", "codeSnapshot");

-- CreateIndex
CREATE INDEX "daily_sheets_entryDate_status_idx" ON "daily_sheets"("entryDate", "status");

-- CreateIndex
CREATE UNIQUE INDEX "daily_sheets_monthlyKpiId_entryDate_key" ON "daily_sheets"("monthlyKpiId", "entryDate");

-- CreateIndex
CREATE INDEX "daily_values_monthlyKpiItemId_idx" ON "daily_values"("monthlyKpiItemId");

-- CreateIndex
CREATE UNIQUE INDEX "daily_values_dailySheetId_monthlyKpiItemId_key" ON "daily_values"("dailySheetId", "monthlyKpiItemId");

-- CreateIndex
CREATE UNIQUE INDEX "evidence_storagePath_key" ON "evidence"("storagePath");

-- CreateIndex
CREATE INDEX "evidence_dailySheetId_idx" ON "evidence"("dailySheetId");

-- CreateIndex
CREATE UNIQUE INDEX "notifications_dedupeKey_key" ON "notifications"("dedupeKey");

-- CreateIndex
CREATE INDEX "notifications_userId_readAt_createdAt_idx" ON "notifications"("userId", "readAt", "createdAt");

-- CreateIndex
CREATE INDEX "audit_events_subjectType_subjectId_createdAt_idx" ON "audit_events"("subjectType", "subjectId", "createdAt");

-- CreateIndex
CREATE INDEX "audit_events_actorId_createdAt_idx" ON "audit_events"("actorId", "createdAt");

-- AddForeignKey
ALTER TABLE "sessions" ADD CONSTRAINT "sessions_userId_fkey" FOREIGN KEY ("userId") REFERENCES "users"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "accounts" ADD CONSTRAINT "accounts_userId_fkey" FOREIGN KEY ("userId") REFERENCES "users"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "employees" ADD CONSTRAINT "employees_userId_fkey" FOREIGN KEY ("userId") REFERENCES "users"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "employees" ADD CONSTRAINT "employees_branchId_fkey" FOREIGN KEY ("branchId") REFERENCES "branches"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "employees" ADD CONSTRAINT "employees_positionId_fkey" FOREIGN KEY ("positionId") REFERENCES "positions"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "employees" ADD CONSTRAINT "employees_supervisorId_fkey" FOREIGN KEY ("supervisorId") REFERENCES "employees"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "employees" ADD CONSTRAINT "employees_managerId_fkey" FOREIGN KEY ("managerId") REFERENCES "employees"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "kpi_templates" ADD CONSTRAINT "kpi_templates_positionId_fkey" FOREIGN KEY ("positionId") REFERENCES "positions"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "kpi_indicators" ADD CONSTRAINT "kpi_indicators_templateId_fkey" FOREIGN KEY ("templateId") REFERENCES "kpi_templates"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "kpi_periods" ADD CONSTRAINT "kpi_periods_createdById_fkey" FOREIGN KEY ("createdById") REFERENCES "users"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "monthly_kpis" ADD CONSTRAINT "monthly_kpis_periodId_fkey" FOREIGN KEY ("periodId") REFERENCES "kpi_periods"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "monthly_kpis" ADD CONSTRAINT "monthly_kpis_employeeId_fkey" FOREIGN KEY ("employeeId") REFERENCES "employees"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "monthly_kpis" ADD CONSTRAINT "monthly_kpis_branchIdSnapshot_fkey" FOREIGN KEY ("branchIdSnapshot") REFERENCES "branches"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "monthly_kpis" ADD CONSTRAINT "monthly_kpis_positionIdSnapshot_fkey" FOREIGN KEY ("positionIdSnapshot") REFERENCES "positions"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "monthly_kpis" ADD CONSTRAINT "monthly_kpis_supervisorIdSnapshot_fkey" FOREIGN KEY ("supervisorIdSnapshot") REFERENCES "employees"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "monthly_kpis" ADD CONSTRAINT "monthly_kpis_managerIdSnapshot_fkey" FOREIGN KEY ("managerIdSnapshot") REFERENCES "employees"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "monthly_kpis" ADD CONSTRAINT "monthly_kpis_finalizedById_fkey" FOREIGN KEY ("finalizedById") REFERENCES "users"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "monthly_kpis" ADD CONSTRAINT "monthly_kpis_reopenedById_fkey" FOREIGN KEY ("reopenedById") REFERENCES "users"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "monthly_kpi_items" ADD CONSTRAINT "monthly_kpi_items_monthlyKpiId_fkey" FOREIGN KEY ("monthlyKpiId") REFERENCES "monthly_kpis"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "daily_sheets" ADD CONSTRAINT "daily_sheets_monthlyKpiId_fkey" FOREIGN KEY ("monthlyKpiId") REFERENCES "monthly_kpis"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "daily_sheets" ADD CONSTRAINT "daily_sheets_enteredById_fkey" FOREIGN KEY ("enteredById") REFERENCES "users"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "daily_sheets" ADD CONSTRAINT "daily_sheets_managerReviewedById_fkey" FOREIGN KEY ("managerReviewedById") REFERENCES "users"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "daily_values" ADD CONSTRAINT "daily_values_dailySheetId_fkey" FOREIGN KEY ("dailySheetId") REFERENCES "daily_sheets"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "daily_values" ADD CONSTRAINT "daily_values_monthlyKpiItemId_fkey" FOREIGN KEY ("monthlyKpiItemId") REFERENCES "monthly_kpi_items"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "evidence" ADD CONSTRAINT "evidence_dailySheetId_fkey" FOREIGN KEY ("dailySheetId") REFERENCES "daily_sheets"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "evidence" ADD CONSTRAINT "evidence_uploadedById_fkey" FOREIGN KEY ("uploadedById") REFERENCES "users"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "notifications" ADD CONSTRAINT "notifications_userId_fkey" FOREIGN KEY ("userId") REFERENCES "users"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "audit_events" ADD CONSTRAINT "audit_events_actorId_fkey" FOREIGN KEY ("actorId") REFERENCES "users"("id") ON DELETE SET NULL ON UPDATE CASCADE;
