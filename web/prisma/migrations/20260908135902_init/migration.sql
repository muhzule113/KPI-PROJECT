-- CreateEnum
CREATE TYPE "UserRole" AS ENUM ('super_admin', 'kpi_admin', 'auditor', 'owner_manager', 'supervisor', 'employee');

-- CreateEnum
CREATE TYPE "EmployeeStatus" AS ENUM ('active', 'inactive', 'resigned', 'leave');

-- CreateEnum
CREATE TYPE "TemplateVersionStatus" AS ENUM ('draft', 'active', 'retired');

-- CreateEnum
CREATE TYPE "PeriodStatus" AS ENUM ('DRAFT', 'READY', 'OPEN', 'SUBMISSION_CLOSED', 'IN_REVIEW', 'WAITING_APPROVAL', 'PUBLISHED', 'LOCKED', 'CANCELLED');

-- CreateEnum
CREATE TYPE "EmployeeKpiStatus" AS ENUM ('draft', 'submitted', 'under_review', 'revision_required', 'verified', 'pending_approval', 'approved', 'locked');

-- CreateEnum
CREATE TYPE "KpiItemStatus" AS ENUM ('not_started', 'draft', 'submitted', 'under_review', 'revision_required', 'verified', 'assessed', 'locked');

-- CreateEnum
CREATE TYPE "CalculationStatus" AS ENUM ('pending', 'calculated', 'unscorable');

-- CreateEnum
CREATE TYPE "DailyReviewStatus" AS ENUM ('pending', 'approved', 'revision_required');

-- CreateEnum
CREATE TYPE "ReviewStatus" AS ENUM ('in_progress', 'verified', 'revision_requested');

-- CreateEnum
CREATE TYPE "CorrectionStatus" AS ENUM ('pending', 'approved', 'rejected', 'applied');

-- CreateEnum
CREATE TYPE "ImportBatchStatus" AS ENUM ('uploaded', 'scanning', 'queued', 'parsing', 'normalizing', 'validating', 'ready_for_preview', 'needs_mapping', 'needs_review', 'confirmed', 'committing', 'completed', 'completed_with_warnings', 'failed', 'cancelled', 'superseded');

-- CreateEnum
CREATE TYPE "TicketStatus" AS ENUM ('intake', 'diagnosing', 'waiting_consent', 'waiting_sparepart', 'in_progress', 'qc_ready', 'completed', 'cancelled_unrepairable', 'cancelled', 'delivered');

-- CreateEnum
CREATE TYPE "PaymentStatus" AS ENUM ('unpaid', 'partial', 'paid', 'waived');

-- CreateTable
CREATE TABLE "users" (
    "id" TEXT NOT NULL,
    "name" TEXT NOT NULL,
    "email" TEXT NOT NULL,
    "emailVerified" BOOLEAN NOT NULL DEFAULT false,
    "image" TEXT,
    "isActive" BOOLEAN NOT NULL DEFAULT true,
    "role" "UserRole" NOT NULL DEFAULT 'employee',
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
    "address" TEXT,
    "phone" TEXT,
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
    "department" TEXT NOT NULL DEFAULT 'Operasional',
    "description" TEXT,
    "isActive" BOOLEAN NOT NULL DEFAULT true,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "positions_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "employees" (
    "id" TEXT NOT NULL,
    "userId" TEXT,
    "employeeNumber" TEXT NOT NULL,
    "name" TEXT NOT NULL,
    "email" TEXT NOT NULL,
    "phone" TEXT,
    "positionId" TEXT NOT NULL,
    "branchId" TEXT NOT NULL,
    "supervisorId" TEXT,
    "joinedAt" DATE NOT NULL,
    "endedAt" DATE,
    "status" "EmployeeStatus" NOT NULL DEFAULT 'active',
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "employees_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "employee_placements" (
    "id" TEXT NOT NULL,
    "employeeId" TEXT NOT NULL,
    "positionId" TEXT NOT NULL,
    "branchId" TEXT NOT NULL,
    "supervisorId" TEXT,
    "effectiveFrom" DATE NOT NULL,
    "effectiveUntil" DATE,
    "notes" TEXT,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "employee_placements_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "kpi_definitions" (
    "id" TEXT NOT NULL,
    "code" TEXT NOT NULL,
    "name" TEXT NOT NULL,
    "metricType" TEXT NOT NULL DEFAULT 'percentage',
    "unit" TEXT NOT NULL DEFAULT '%',
    "direction" TEXT NOT NULL DEFAULT 'higher',
    "defaultFormula" TEXT NOT NULL DEFAULT 'higher_is_better',
    "sourceType" TEXT NOT NULL DEFAULT 'system',
    "description" TEXT,
    "isActive" BOOLEAN NOT NULL DEFAULT true,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "kpi_definitions_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "kpi_rating_schemes" (
    "id" TEXT NOT NULL,
    "name" TEXT NOT NULL,
    "version" INTEGER NOT NULL DEFAULT 1,
    "scoreCap" DECIMAL(9,6) NOT NULL DEFAULT 100,
    "description" TEXT,
    "isDefault" BOOLEAN NOT NULL DEFAULT false,
    "isActive" BOOLEAN NOT NULL DEFAULT true,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "kpi_rating_schemes_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "kpi_rating_bands" (
    "id" TEXT NOT NULL,
    "ratingSchemeId" TEXT NOT NULL,
    "code" TEXT NOT NULL,
    "label" TEXT NOT NULL,
    "minScore" DECIMAL(8,2) NOT NULL,
    "maxScore" DECIMAL(8,2) NOT NULL,
    "manualScore" DECIMAL(8,2),
    "color" TEXT NOT NULL DEFAULT '#047857',
    "badgeIcon" TEXT,
    "sortOrder" INTEGER NOT NULL DEFAULT 1,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "kpi_rating_bands_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "kpi_templates" (
    "id" TEXT NOT NULL,
    "code" TEXT NOT NULL,
    "name" TEXT NOT NULL,
    "positionId" TEXT NOT NULL,
    "isActive" BOOLEAN NOT NULL DEFAULT true,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "kpi_templates_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "kpi_template_versions" (
    "id" TEXT NOT NULL,
    "kpiTemplateId" TEXT NOT NULL,
    "versionNumber" INTEGER NOT NULL DEFAULT 1,
    "status" "TemplateVersionStatus" NOT NULL DEFAULT 'draft',
    "totalWeight" DECIMAL(8,2) NOT NULL DEFAULT 0,
    "ratingSchemeId" TEXT,
    "checksum" TEXT,
    "effectiveFrom" DATE,
    "effectiveUntil" DATE,
    "activatedById" TEXT,
    "activatedAt" TIMESTAMP(3),
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "kpi_template_versions_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "kpi_template_items" (
    "id" TEXT NOT NULL,
    "templateVersionId" TEXT NOT NULL,
    "kpiDefinitionId" TEXT NOT NULL,
    "weight" DECIMAL(5,2) NOT NULL,
    "targetValue" DECIMAL(12,2),
    "targetUnit" TEXT NOT NULL DEFAULT '%',
    "targetJson" JSONB,
    "formulaKey" TEXT NOT NULL DEFAULT 'higher_is_better',
    "formulaParams" JSONB,
    "sourceType" TEXT NOT NULL DEFAULT 'system',
    "evidenceRequired" BOOLEAN NOT NULL DEFAULT false,
    "isMandatory" BOOLEAN NOT NULL DEFAULT true,
    "sortOrder" INTEGER NOT NULL DEFAULT 1,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "kpi_template_items_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "kpi_rubrics" (
    "id" TEXT NOT NULL,
    "templateItemId" TEXT NOT NULL,
    "name" TEXT NOT NULL,
    "description" TEXT,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "kpi_rubrics_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "kpi_rubric_criteria" (
    "id" TEXT NOT NULL,
    "rubricId" TEXT NOT NULL,
    "criterionText" TEXT NOT NULL,
    "points" DECIMAL(5,2) NOT NULL DEFAULT 1,
    "isMandatory" BOOLEAN NOT NULL DEFAULT true,
    "sortOrder" INTEGER NOT NULL DEFAULT 1,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "kpi_rubric_criteria_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "kpi_periods" (
    "id" TEXT NOT NULL,
    "name" TEXT NOT NULL,
    "year" INTEGER NOT NULL,
    "month" INTEGER NOT NULL,
    "startDate" DATE NOT NULL,
    "endDate" DATE NOT NULL,
    "submissionDeadline" TIMESTAMP(3) NOT NULL,
    "reviewDeadline" TIMESTAMP(3) NOT NULL,
    "approvalDeadline" TIMESTAMP(3) NOT NULL,
    "status" "PeriodStatus" NOT NULL DEFAULT 'DRAFT',
    "totalEligibleEmployees" INTEGER NOT NULL DEFAULT 0,
    "createdById" TEXT,
    "openedAt" TIMESTAMP(3),
    "closedAt" TIMESTAMP(3),
    "publishedAt" TIMESTAMP(3),
    "lockedAt" TIMESTAMP(3),
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "kpi_periods_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "kpi_period_branches" (
    "id" TEXT NOT NULL,
    "periodId" TEXT NOT NULL,
    "branchId" TEXT NOT NULL,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "kpi_period_branches_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "employee_kpis" (
    "id" TEXT NOT NULL,
    "periodId" TEXT NOT NULL,
    "employeeId" TEXT NOT NULL,
    "employeeNumberSnapshot" TEXT NOT NULL,
    "employeeNameSnapshot" TEXT NOT NULL,
    "templateVersionId" TEXT NOT NULL,
    "supervisorIdSnapshot" TEXT,
    "managerIdSnapshot" TEXT,
    "branchIdSnapshot" TEXT NOT NULL,
    "positionIdSnapshot" TEXT NOT NULL,
    "placementIdSnapshot" TEXT,
    "positionCodeSnapshot" TEXT NOT NULL,
    "eligibility" TEXT NOT NULL DEFAULT 'full',
    "scoreCapSnapshot" DECIMAL(9,6) NOT NULL DEFAULT 100,
    "ratingBandsSnapshot" JSONB,
    "status" "EmployeeKpiStatus" NOT NULL DEFAULT 'draft',
    "progressPercentage" DECIMAL(5,2) NOT NULL DEFAULT 0,
    "finalScore" DECIMAL(8,2),
    "ratingCode" TEXT,
    "ratingLabel" TEXT,
    "revisionNumber" INTEGER NOT NULL DEFAULT 0,
    "rowVersion" INTEGER NOT NULL DEFAULT 1,
    "submittedAt" TIMESTAMP(3),
    "verifiedAt" TIMESTAMP(3),
    "approvedAt" TIMESTAMP(3),
    "lockedAt" TIMESTAMP(3),
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "employee_kpis_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "employee_kpi_items" (
    "id" TEXT NOT NULL,
    "employeeKpiId" TEXT NOT NULL,
    "kpiDefinitionId" TEXT,
    "definitionCodeSnapshot" TEXT NOT NULL,
    "nameSnapshot" TEXT NOT NULL,
    "weightSnapshot" DECIMAL(5,2) NOT NULL,
    "targetValueSnapshot" DECIMAL(12,2),
    "targetUnitSnapshot" TEXT NOT NULL DEFAULT '%',
    "targetJsonSnapshot" JSONB,
    "formulaKeySnapshot" TEXT NOT NULL,
    "formulaParamsSnapshot" JSONB,
    "sourceTypeSnapshot" TEXT NOT NULL,
    "evidenceRequiredSnapshot" BOOLEAN NOT NULL DEFAULT false,
    "rubricSnapshot" JSONB,
    "status" "KpiItemStatus" NOT NULL DEFAULT 'not_started',
    "actualDecimal" DECIMAL(18,6),
    "actualJson" JSONB,
    "achievementPercentage" DECIMAL(12,6),
    "weightedScore" DECIMAL(12,6),
    "calculationStatus" "CalculationStatus" NOT NULL DEFAULT 'pending',
    "calculationNote" TEXT,
    "managerDecision" TEXT,
    "managerNote" TEXT,
    "managerEvidenceJson" JSONB,
    "managerDecidedById" TEXT,
    "managerDecidedAt" TIMESTAMP(3),
    "rowVersion" INTEGER NOT NULL DEFAULT 1,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "employee_kpi_items_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "kpi_daily_entries" (
    "id" TEXT NOT NULL,
    "employeeKpiItemId" TEXT NOT NULL,
    "entryDate" DATE NOT NULL,
    "employeeActualDecimal" DECIMAL(12,2),
    "employeeActualJson" JSONB,
    "employeeNote" TEXT,
    "employeeEnteredById" TEXT,
    "entryStatus" TEXT NOT NULL DEFAULT 'submitted',
    "employeeSubmittedAt" TIMESTAMP(3),
    "systemActualDecimal" DECIMAL(12,6),
    "systemActualJson" JSONB,
    "supervisorActualDecimal" DECIMAL(12,2),
    "supervisorActualJson" JSONB,
    "supervisorAnswersJson" JSONB,
    "supervisorScorePercentage" DECIMAL(8,2),
    "supervisorNote" TEXT,
    "supervisorAssessedById" TEXT,
    "supervisorStatus" "DailyReviewStatus" NOT NULL DEFAULT 'pending',
    "supervisorAssessedAt" TIMESTAMP(3),
    "managerActualDecimal" DECIMAL(12,2),
    "managerActualJson" JSONB,
    "managerAnswersJson" JSONB,
    "managerScorePercentage" DECIMAL(8,2),
    "managerNote" TEXT,
    "managerAssessedById" TEXT,
    "managerStatus" "DailyReviewStatus" NOT NULL DEFAULT 'pending',
    "managerAssessedAt" TIMESTAMP(3),
    "rowVersion" INTEGER NOT NULL DEFAULT 1,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "kpi_daily_entries_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "kpi_actual_entries" (
    "id" TEXT NOT NULL,
    "employeeKpiItemId" TEXT NOT NULL,
    "inputById" TEXT NOT NULL,
    "actualValue" DECIMAL(12,2),
    "actualJson" JSONB,
    "notes" TEXT,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "kpi_actual_entries_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "kpi_evidences" (
    "id" TEXT NOT NULL,
    "employeeKpiItemId" TEXT NOT NULL,
    "filePath" TEXT NOT NULL,
    "fileName" TEXT NOT NULL,
    "fileSize" BIGINT NOT NULL,
    "mimeType" TEXT NOT NULL,
    "sha256Hash" TEXT NOT NULL,
    "scanStatus" TEXT NOT NULL DEFAULT 'pending',
    "scannedAt" TIMESTAMP(3),
    "scanNote" TEXT,
    "uploadedById" TEXT NOT NULL,
    "description" TEXT,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "kpi_evidences_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "kpi_reviews" (
    "id" TEXT NOT NULL,
    "employeeKpiId" TEXT NOT NULL,
    "reviewerId" TEXT NOT NULL,
    "status" "ReviewStatus" NOT NULL DEFAULT 'in_progress',
    "notes" TEXT,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "kpi_reviews_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "kpi_review_items" (
    "id" TEXT NOT NULL,
    "kpiReviewId" TEXT NOT NULL,
    "employeeKpiItemId" TEXT NOT NULL,
    "decision" TEXT NOT NULL,
    "supervisorNote" TEXT,
    "reason" TEXT,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "kpi_review_items_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "kpi_assessments" (
    "id" TEXT NOT NULL,
    "employeeKpiItemId" TEXT NOT NULL,
    "kpiReviewId" TEXT,
    "assessedById" TEXT NOT NULL,
    "scorePoints" DECIMAL(6,2) NOT NULL DEFAULT 0,
    "totalPoints" DECIMAL(6,2) NOT NULL DEFAULT 0,
    "calculatedAchievement" DECIMAL(8,2) NOT NULL DEFAULT 0,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "kpi_assessments_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "kpi_assessment_answers" (
    "id" TEXT NOT NULL,
    "kpiAssessmentId" TEXT NOT NULL,
    "criterionId" TEXT,
    "criterionText" TEXT NOT NULL,
    "isFulfilled" BOOLEAN NOT NULL DEFAULT false,
    "pointsEarned" DECIMAL(5,2) NOT NULL DEFAULT 0,
    "notes" TEXT,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "kpi_assessment_answers_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "kpi_approvals" (
    "id" TEXT NOT NULL,
    "employeeKpiId" TEXT NOT NULL,
    "approverId" TEXT NOT NULL,
    "action" TEXT NOT NULL,
    "reason" TEXT,
    "rowVersionSnapshot" INTEGER NOT NULL,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "kpi_approvals_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "kpi_correction_requests" (
    "id" TEXT NOT NULL,
    "employeeKpiId" TEXT NOT NULL,
    "requestedById" TEXT NOT NULL,
    "approvedById" TEXT,
    "reason" TEXT NOT NULL,
    "beforeJson" JSONB NOT NULL,
    "afterJson" JSONB NOT NULL,
    "status" "CorrectionStatus" NOT NULL DEFAULT 'pending',
    "rejectionReason" TEXT,
    "rowVersionSnapshot" INTEGER,
    "pendingUniqueKey" TEXT,
    "appliedAt" TIMESTAMP(3),
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "kpi_correction_requests_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "kpi_calculation_runs" (
    "id" TEXT NOT NULL,
    "employeeKpiId" TEXT NOT NULL,
    "runType" TEXT NOT NULL DEFAULT 'recalculation',
    "inputSnapshot" JSONB NOT NULL,
    "outputSnapshot" JSONB NOT NULL,
    "totalScore" DECIMAL(8,2),
    "ratingCode" TEXT,
    "calculatedById" TEXT,
    "calculatedAt" TIMESTAMP(3) NOT NULL,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "kpi_calculation_runs_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "spareparts" (
    "id" TEXT NOT NULL,
    "branchId" TEXT,
    "code" TEXT NOT NULL,
    "productType" TEXT NOT NULL DEFAULT 'sparepart',
    "name" TEXT NOT NULL,
    "category" TEXT NOT NULL,
    "compatibleModels" TEXT,
    "stockQuantity" INTEGER NOT NULL DEFAULT 0,
    "minStockAlert" INTEGER NOT NULL DEFAULT 5,
    "purchasePrice" DECIMAL(15,2) NOT NULL DEFAULT 0,
    "sellingPrice" DECIMAL(15,2) NOT NULL DEFAULT 0,
    "isCritical" BOOLEAN NOT NULL DEFAULT false,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "spareparts_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "service_tickets" (
    "id" TEXT NOT NULL,
    "ticketNumber" TEXT NOT NULL,
    "customerName" TEXT NOT NULL,
    "customerPhone" TEXT NOT NULL,
    "customerAddress" TEXT,
    "deviceBrand" TEXT NOT NULL,
    "deviceModel" TEXT NOT NULL,
    "imeiOrSerial" TEXT,
    "passcodeCiphertext" TEXT,
    "physicalCondition" TEXT,
    "initialComplaint" TEXT NOT NULL,
    "customerNeeds" TEXT,
    "estimatedCost" DECIMAL(15,2) NOT NULL DEFAULT 0,
    "finalCost" DECIMAL(15,2) NOT NULL DEFAULT 0,
    "estimatedCompletionAt" TIMESTAMP(3),
    "branchId" TEXT NOT NULL,
    "periodId" TEXT,
    "intakeByEmployeeId" TEXT,
    "cashierEmployeeId" TEXT,
    "technicianEmployeeId" TEXT,
    "customerConsentByEmployeeId" TEXT,
    "paymentRecordedByEmployeeId" TEXT,
    "deliveredByEmployeeId" TEXT,
    "status" "TicketStatus" NOT NULL DEFAULT 'intake',
    "rowVersion" INTEGER NOT NULL DEFAULT 1,
    "resultStatus" TEXT NOT NULL DEFAULT 'pending',
    "diagnosisNotes" TEXT,
    "actionNotes" TEXT,
    "qcChecklistJson" JSONB,
    "isWarrantyReturn" BOOLEAN NOT NULL DEFAULT false,
    "warrantyReturnedFromTicketId" TEXT,
    "customerConsentStatus" TEXT NOT NULL DEFAULT 'pending',
    "customerConsentAt" TIMESTAMP(3),
    "customerConsentNotes" TEXT,
    "paymentStatus" "PaymentStatus" NOT NULL DEFAULT 'unpaid',
    "paidAmount" DECIMAL(15,2) NOT NULL DEFAULT 0,
    "paymentRecordedAt" TIMESTAMP(3),
    "paymentExceptionType" TEXT,
    "paymentExceptionReason" TEXT,
    "paymentExceptionApprovedByUserId" TEXT,
    "paymentExceptionApprovedAt" TIMESTAMP(3),
    "deliveryRecipientType" TEXT,
    "deliveryRecipientName" TEXT,
    "deliveryNotes" TEXT,
    "unrepairableReason" TEXT,
    "customerDeclinedReason" TEXT,
    "cancellationReason" TEXT,
    "technicalEvidenceJson" JSONB,
    "serviceCategory" TEXT NOT NULL DEFAULT 'general',
    "serviceComplexity" TEXT NOT NULL DEFAULT 'light',
    "slaVersion" TEXT NOT NULL DEFAULT 'v1',
    "slaBaselineDueAt" TIMESTAMP(3),
    "slaDueAt" TIMESTAMP(3),
    "slaBreachedAt" TIMESTAMP(3),
    "sparepartWaitMinutes" INTEGER NOT NULL DEFAULT 0,
    "slaSnapshotJson" JSONB,
    "warrantyExpiresAt" TIMESTAMP(3),
    "warrantyReviewStatus" TEXT NOT NULL DEFAULT 'not_applicable',
    "warrantyReviewReason" TEXT,
    "warrantyReviewedByUserId" TEXT,
    "warrantyReviewedAt" TIMESTAMP(3),
    "startedAt" TIMESTAMP(3),
    "completedAt" TIMESTAMP(3),
    "deliveredAt" TIMESTAMP(3),
    "cancelledAt" TIMESTAMP(3),
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "service_tickets_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "sparepart_requests" (
    "id" TEXT NOT NULL,
    "serviceTicketId" TEXT NOT NULL,
    "sparepartId" TEXT NOT NULL,
    "technicianEmployeeId" TEXT,
    "warehouseEmployeeId" TEXT,
    "confirmedByEmployeeId" TEXT,
    "quantity" INTEGER NOT NULL DEFAULT 1,
    "status" TEXT NOT NULL DEFAULT 'pending',
    "requestedAt" TIMESTAMP(3) NOT NULL,
    "fulfilledAt" TIMESTAMP(3),
    "confirmedAt" TIMESTAMP(3),
    "availabilityNote" TEXT,
    "slaDeadlineAt" TIMESTAMP(3),
    "notes" TEXT,
    "pendingUniqueKey" TEXT,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "sparepart_requests_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "stock_movements" (
    "id" TEXT NOT NULL,
    "sparepartId" TEXT NOT NULL,
    "movementType" TEXT NOT NULL,
    "quantity" INTEGER NOT NULL,
    "stockBefore" INTEGER,
    "stockAfter" INTEGER,
    "referenceType" TEXT,
    "referenceId" TEXT,
    "note" TEXT,
    "userId" TEXT,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "stock_movements_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "stock_opnames" (
    "id" TEXT NOT NULL,
    "code" TEXT NOT NULL,
    "periodId" TEXT,
    "branchId" TEXT NOT NULL,
    "status" TEXT NOT NULL DEFAULT 'draft',
    "deadline" DATE,
    "completedAt" TIMESTAMP(3),
    "createdById" TEXT,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "stock_opnames_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "stock_opname_items" (
    "id" TEXT NOT NULL,
    "stockOpnameId" TEXT NOT NULL,
    "sparepartId" TEXT NOT NULL,
    "systemStock" INTEGER NOT NULL DEFAULT 0,
    "physicalStock" INTEGER,
    "difference" INTEGER NOT NULL DEFAULT 0,
    "isCounted" BOOLEAN NOT NULL DEFAULT false,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "stock_opname_items_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "attendances" (
    "id" TEXT NOT NULL,
    "employeeId" TEXT NOT NULL,
    "branchId" TEXT NOT NULL,
    "attendanceDate" DATE NOT NULL,
    "status" TEXT NOT NULL,
    "checkInTime" TIME(0),
    "checkOutTime" TIME(0),
    "note" TEXT,
    "recordedById" TEXT,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "attendances_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "admin_work_logs" (
    "id" TEXT NOT NULL,
    "employeeId" TEXT NOT NULL,
    "periodId" TEXT,
    "workDate" DATE NOT NULL,
    "recordsInput" INTEGER NOT NULL DEFAULT 0,
    "recordsCorrected" INTEGER NOT NULL DEFAULT 0,
    "documentsEligible" INTEGER NOT NULL DEFAULT 0,
    "documentsComplete" INTEGER NOT NULL DEFAULT 0,
    "reconciliationsTotal" INTEGER NOT NULL DEFAULT 0,
    "reconciliationsSuccess" INTEGER NOT NULL DEFAULT 0,
    "notes" TEXT,
    "evidencePath" TEXT,
    "recordedById" TEXT,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "admin_work_logs_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "complaints" (
    "id" TEXT NOT NULL,
    "code" TEXT NOT NULL,
    "complaintDate" DATE NOT NULL,
    "employeeId" TEXT,
    "serviceTicketId" TEXT,
    "channel" TEXT NOT NULL,
    "category" TEXT,
    "severity" TEXT NOT NULL DEFAULT 'medium',
    "status" TEXT NOT NULL DEFAULT 'open',
    "description" TEXT NOT NULL,
    "slaDeadline" TIMESTAMP(3),
    "resolvedAt" TIMESTAMP(3),
    "resolutionNotes" TEXT,
    "recordedById" TEXT,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "complaints_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "coaching_logs" (
    "id" TEXT NOT NULL,
    "supervisorId" TEXT NOT NULL,
    "employeeId" TEXT NOT NULL,
    "coachingDate" DATE NOT NULL,
    "topic" TEXT NOT NULL,
    "notes" TEXT,
    "targetMet" BOOLEAN,
    "followUpDate" DATE,
    "periodId" TEXT,
    "recordedById" TEXT,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "coaching_logs_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "customer_feedbacks" (
    "id" TEXT NOT NULL,
    "serviceTicketId" TEXT NOT NULL,
    "csEmployeeId" TEXT,
    "technicianEmployeeId" TEXT,
    "customerName" TEXT NOT NULL,
    "rating" INTEGER NOT NULL DEFAULT 5,
    "technicianRating" INTEGER,
    "comments" TEXT,
    "followUpOntime" BOOLEAN NOT NULL DEFAULT true,
    "feedbackChannel" TEXT NOT NULL DEFAULT 'in_store',
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "customer_feedbacks_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "feedback_follow_ups" (
    "id" TEXT NOT NULL,
    "customerFeedbackId" TEXT NOT NULL,
    "serviceTicketId" TEXT NOT NULL,
    "assignedEmployeeId" TEXT,
    "assignedByUserId" TEXT,
    "status" TEXT NOT NULL DEFAULT 'pending',
    "dueAt" TIMESTAMP(3),
    "firstContactedAt" TIMESTAMP(3),
    "completedAt" TIMESTAMP(3),
    "contactChannel" TEXT,
    "outcome" TEXT,
    "responseSummary" TEXT,
    "evidenceJson" JSONB,
    "completedByUserId" TEXT,
    "rowVersion" INTEGER NOT NULL DEFAULT 1,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "feedback_follow_ups_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "import_mapping_templates" (
    "id" TEXT NOT NULL,
    "name" TEXT NOT NULL,
    "sourceApplication" TEXT NOT NULL DEFAULT 'POS_SYSTEM',
    "description" TEXT,
    "isActive" BOOLEAN NOT NULL DEFAULT true,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "import_mapping_templates_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "import_mapping_versions" (
    "id" TEXT NOT NULL,
    "mappingTemplateId" TEXT NOT NULL,
    "versionNumber" INTEGER NOT NULL DEFAULT 1,
    "mappingsJson" JSONB NOT NULL,
    "isActive" BOOLEAN NOT NULL DEFAULT true,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "import_mapping_versions_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "import_batches" (
    "id" TEXT NOT NULL,
    "fileName" TEXT NOT NULL,
    "filePath" TEXT NOT NULL,
    "fileHashSha256" TEXT,
    "sourceApplication" TEXT NOT NULL DEFAULT 'POS_SYSTEM',
    "mappingVersionId" TEXT,
    "periodId" TEXT NOT NULL,
    "branchId" TEXT NOT NULL,
    "currency" CHAR(3) NOT NULL DEFAULT 'IDR',
    "uploaderId" TEXT NOT NULL,
    "status" "ImportBatchStatus" NOT NULL DEFAULT 'uploaded',
    "scanStatus" TEXT NOT NULL DEFAULT 'quarantine',
    "scannedAt" TIMESTAMP(3),
    "scanNote" TEXT,
    "supersededById" TEXT,
    "totalRows" INTEGER NOT NULL DEFAULT 0,
    "validRows" INTEGER NOT NULL DEFAULT 0,
    "warningRows" INTEGER NOT NULL DEFAULT 0,
    "errorRows" INTEGER NOT NULL DEFAULT 0,
    "duplicateRows" INTEGER NOT NULL DEFAULT 0,
    "summaryJson" JSONB,
    "issuesJson" JSONB,
    "confirmedAt" TIMESTAMP(3),
    "confirmedById" TEXT,
    "warningsAcknowledgedAt" TIMESTAMP(3),
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "import_batches_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "cashier_transactions" (
    "id" TEXT NOT NULL,
    "importBatchId" TEXT NOT NULL,
    "periodId" TEXT NOT NULL,
    "sourceApplication" TEXT NOT NULL DEFAULT 'POS_SYSTEM',
    "cashierEmployeeId" TEXT,
    "cashierNameRaw" TEXT,
    "transactionNumber" TEXT NOT NULL,
    "businessKey" TEXT NOT NULL,
    "transactionDate" TIMESTAMP(3) NOT NULL,
    "transactionAmount" DECIMAL(14,2) NOT NULL DEFAULT 0,
    "systemCashAmount" DECIMAL(14,2) NOT NULL DEFAULT 0,
    "actualCashAmount" DECIMAL(14,2) NOT NULL DEFAULT 0,
    "cashDifference" DECIMAL(14,2) NOT NULL DEFAULT 0,
    "durationSeconds" INTEGER,
    "status" TEXT NOT NULL DEFAULT 'SUCCESS',
    "isDuplicate" BOOLEAN NOT NULL DEFAULT false,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "cashier_transactions_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "system_notifications" (
    "id" TEXT NOT NULL,
    "userId" TEXT NOT NULL,
    "title" TEXT NOT NULL,
    "body" TEXT NOT NULL,
    "type" TEXT NOT NULL DEFAULT 'info',
    "entityType" TEXT,
    "entityId" TEXT,
    "actionUrl" TEXT,
    "dedupeKey" TEXT,
    "isRead" BOOLEAN NOT NULL DEFAULT false,
    "readAt" TIMESTAMP(3),
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "system_notifications_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "audit_events" (
    "id" TEXT NOT NULL,
    "occurredAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "actorType" TEXT NOT NULL DEFAULT 'user',
    "actorId" TEXT,
    "action" TEXT NOT NULL,
    "subjectType" TEXT NOT NULL,
    "subjectId" TEXT NOT NULL,
    "beforeJson" JSONB,
    "afterJson" JSONB,
    "reason" TEXT,
    "ipAddress" TEXT,
    "userAgent" TEXT,
    "requestId" TEXT,
    "correlationId" TEXT,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "audit_events_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "report_submissions" (
    "id" TEXT NOT NULL,
    "periodId" TEXT NOT NULL,
    "employeeId" TEXT NOT NULL,
    "reportType" TEXT NOT NULL,
    "reportDate" DATE NOT NULL,
    "deadlineAt" TIMESTAMP(3) NOT NULL,
    "submittedAt" TIMESTAMP(3),
    "isOnTime" BOOLEAN,
    "status" TEXT NOT NULL DEFAULT 'scheduled',
    "source" TEXT NOT NULL DEFAULT 'system',
    "contentSnapshot" JSONB,
    "submittedById" TEXT,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "report_submissions_pkey" PRIMARY KEY ("id")
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
CREATE INDEX "employees_positionId_branchId_status_idx" ON "employees"("positionId", "branchId", "status");

-- CreateIndex
CREATE INDEX "employee_placements_employeeId_effectiveFrom_effectiveUntil_idx" ON "employee_placements"("employeeId", "effectiveFrom", "effectiveUntil");

-- CreateIndex
CREATE UNIQUE INDEX "kpi_definitions_code_key" ON "kpi_definitions"("code");

-- CreateIndex
CREATE UNIQUE INDEX "kpi_rating_schemes_name_version_key" ON "kpi_rating_schemes"("name", "version");

-- CreateIndex
CREATE UNIQUE INDEX "kpi_rating_bands_ratingSchemeId_code_key" ON "kpi_rating_bands"("ratingSchemeId", "code");

-- CreateIndex
CREATE UNIQUE INDEX "kpi_templates_code_key" ON "kpi_templates"("code");

-- CreateIndex
CREATE UNIQUE INDEX "kpi_template_versions_kpiTemplateId_versionNumber_key" ON "kpi_template_versions"("kpiTemplateId", "versionNumber");

-- CreateIndex
CREATE UNIQUE INDEX "kpi_rubrics_templateItemId_key" ON "kpi_rubrics"("templateItemId");

-- CreateIndex
CREATE INDEX "kpi_periods_status_idx" ON "kpi_periods"("status");

-- CreateIndex
CREATE UNIQUE INDEX "kpi_periods_year_month_key" ON "kpi_periods"("year", "month");

-- CreateIndex
CREATE UNIQUE INDEX "kpi_period_branches_periodId_branchId_key" ON "kpi_period_branches"("periodId", "branchId");

-- CreateIndex
CREATE INDEX "employee_kpis_periodId_status_idx" ON "employee_kpis"("periodId", "status");

-- CreateIndex
CREATE INDEX "employee_kpis_supervisorIdSnapshot_status_idx" ON "employee_kpis"("supervisorIdSnapshot", "status");

-- CreateIndex
CREATE INDEX "employee_kpis_managerIdSnapshot_status_idx" ON "employee_kpis"("managerIdSnapshot", "status");

-- CreateIndex
CREATE UNIQUE INDEX "employee_kpis_periodId_employeeId_key" ON "employee_kpis"("periodId", "employeeId");

-- CreateIndex
CREATE INDEX "employee_kpi_items_employeeKpiId_status_idx" ON "employee_kpi_items"("employeeKpiId", "status");

-- CreateIndex
CREATE INDEX "employee_kpi_items_employeeKpiId_managerDecision_idx" ON "employee_kpi_items"("employeeKpiId", "managerDecision");

-- CreateIndex
CREATE INDEX "kpi_daily_entries_entryDate_supervisorStatus_idx" ON "kpi_daily_entries"("entryDate", "supervisorStatus");

-- CreateIndex
CREATE INDEX "kpi_daily_entries_entryDate_managerStatus_idx" ON "kpi_daily_entries"("entryDate", "managerStatus");

-- CreateIndex
CREATE UNIQUE INDEX "kpi_daily_entries_employeeKpiItemId_entryDate_key" ON "kpi_daily_entries"("employeeKpiItemId", "entryDate");

-- CreateIndex
CREATE INDEX "kpi_reviews_employeeKpiId_status_idx" ON "kpi_reviews"("employeeKpiId", "status");

-- CreateIndex
CREATE UNIQUE INDEX "kpi_review_items_kpiReviewId_employeeKpiItemId_key" ON "kpi_review_items"("kpiReviewId", "employeeKpiItemId");

-- CreateIndex
CREATE INDEX "kpi_assessments_employeeKpiItemId_idx" ON "kpi_assessments"("employeeKpiItemId");

-- CreateIndex
CREATE INDEX "kpi_approvals_employeeKpiId_createdAt_idx" ON "kpi_approvals"("employeeKpiId", "createdAt");

-- CreateIndex
CREATE UNIQUE INDEX "kpi_correction_requests_pendingUniqueKey_key" ON "kpi_correction_requests"("pendingUniqueKey");

-- CreateIndex
CREATE INDEX "kpi_correction_requests_employeeKpiId_status_idx" ON "kpi_correction_requests"("employeeKpiId", "status");

-- CreateIndex
CREATE INDEX "kpi_calculation_runs_employeeKpiId_calculatedAt_idx" ON "kpi_calculation_runs"("employeeKpiId", "calculatedAt");

-- CreateIndex
CREATE UNIQUE INDEX "spareparts_code_key" ON "spareparts"("code");

-- CreateIndex
CREATE INDEX "spareparts_branchId_isCritical_idx" ON "spareparts"("branchId", "isCritical");

-- CreateIndex
CREATE UNIQUE INDEX "service_tickets_ticketNumber_key" ON "service_tickets"("ticketNumber");

-- CreateIndex
CREATE INDEX "service_tickets_branchId_status_idx" ON "service_tickets"("branchId", "status");

-- CreateIndex
CREATE INDEX "service_tickets_technicianEmployeeId_status_periodId_idx" ON "service_tickets"("technicianEmployeeId", "status", "periodId");

-- CreateIndex
CREATE INDEX "service_tickets_slaDueAt_status_idx" ON "service_tickets"("slaDueAt", "status");

-- CreateIndex
CREATE UNIQUE INDEX "sparepart_requests_pendingUniqueKey_key" ON "sparepart_requests"("pendingUniqueKey");

-- CreateIndex
CREATE INDEX "sparepart_requests_serviceTicketId_status_idx" ON "sparepart_requests"("serviceTicketId", "status");

-- CreateIndex
CREATE INDEX "stock_movements_sparepartId_movementType_idx" ON "stock_movements"("sparepartId", "movementType");

-- CreateIndex
CREATE UNIQUE INDEX "stock_opnames_code_key" ON "stock_opnames"("code");

-- CreateIndex
CREATE INDEX "stock_opnames_branchId_status_idx" ON "stock_opnames"("branchId", "status");

-- CreateIndex
CREATE UNIQUE INDEX "stock_opname_items_stockOpnameId_sparepartId_key" ON "stock_opname_items"("stockOpnameId", "sparepartId");

-- CreateIndex
CREATE INDEX "attendances_attendanceDate_status_idx" ON "attendances"("attendanceDate", "status");

-- CreateIndex
CREATE UNIQUE INDEX "attendances_employeeId_attendanceDate_key" ON "attendances"("employeeId", "attendanceDate");

-- CreateIndex
CREATE UNIQUE INDEX "admin_work_logs_employeeId_workDate_key" ON "admin_work_logs"("employeeId", "workDate");

-- CreateIndex
CREATE UNIQUE INDEX "complaints_code_key" ON "complaints"("code");

-- CreateIndex
CREATE INDEX "complaints_employeeId_status_complaintDate_idx" ON "complaints"("employeeId", "status", "complaintDate");

-- CreateIndex
CREATE INDEX "coaching_logs_supervisorId_coachingDate_idx" ON "coaching_logs"("supervisorId", "coachingDate");

-- CreateIndex
CREATE UNIQUE INDEX "customer_feedbacks_serviceTicketId_key" ON "customer_feedbacks"("serviceTicketId");

-- CreateIndex
CREATE INDEX "customer_feedbacks_csEmployeeId_rating_idx" ON "customer_feedbacks"("csEmployeeId", "rating");

-- CreateIndex
CREATE UNIQUE INDEX "feedback_follow_ups_customerFeedbackId_key" ON "feedback_follow_ups"("customerFeedbackId");

-- CreateIndex
CREATE INDEX "feedback_follow_ups_assignedEmployeeId_status_dueAt_idx" ON "feedback_follow_ups"("assignedEmployeeId", "status", "dueAt");

-- CreateIndex
CREATE UNIQUE INDEX "import_mapping_versions_mappingTemplateId_versionNumber_key" ON "import_mapping_versions"("mappingTemplateId", "versionNumber");

-- CreateIndex
CREATE INDEX "import_batches_periodId_status_idx" ON "import_batches"("periodId", "status");

-- CreateIndex
CREATE UNIQUE INDEX "import_batches_sourceApplication_fileHashSha256_key" ON "import_batches"("sourceApplication", "fileHashSha256");

-- CreateIndex
CREATE INDEX "cashier_transactions_periodId_cashierEmployeeId_idx" ON "cashier_transactions"("periodId", "cashierEmployeeId");

-- CreateIndex
CREATE UNIQUE INDEX "cashier_transactions_periodId_sourceApplication_businessKey_key" ON "cashier_transactions"("periodId", "sourceApplication", "businessKey");

-- CreateIndex
CREATE UNIQUE INDEX "system_notifications_dedupeKey_key" ON "system_notifications"("dedupeKey");

-- CreateIndex
CREATE INDEX "system_notifications_userId_isRead_idx" ON "system_notifications"("userId", "isRead");

-- CreateIndex
CREATE INDEX "audit_events_subjectType_subjectId_occurredAt_idx" ON "audit_events"("subjectType", "subjectId", "occurredAt");

-- CreateIndex
CREATE INDEX "audit_events_actorId_occurredAt_idx" ON "audit_events"("actorId", "occurredAt");

-- CreateIndex
CREATE INDEX "report_submissions_periodId_status_deadlineAt_idx" ON "report_submissions"("periodId", "status", "deadlineAt");

-- CreateIndex
CREATE UNIQUE INDEX "report_submissions_periodId_employeeId_reportType_reportDat_key" ON "report_submissions"("periodId", "employeeId", "reportType", "reportDate");

-- AddForeignKey
ALTER TABLE "sessions" ADD CONSTRAINT "sessions_userId_fkey" FOREIGN KEY ("userId") REFERENCES "users"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "accounts" ADD CONSTRAINT "accounts_userId_fkey" FOREIGN KEY ("userId") REFERENCES "users"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "employees" ADD CONSTRAINT "employees_userId_fkey" FOREIGN KEY ("userId") REFERENCES "users"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "employees" ADD CONSTRAINT "employees_positionId_fkey" FOREIGN KEY ("positionId") REFERENCES "positions"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "employees" ADD CONSTRAINT "employees_branchId_fkey" FOREIGN KEY ("branchId") REFERENCES "branches"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "employees" ADD CONSTRAINT "employees_supervisorId_fkey" FOREIGN KEY ("supervisorId") REFERENCES "employees"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "employee_placements" ADD CONSTRAINT "employee_placements_employeeId_fkey" FOREIGN KEY ("employeeId") REFERENCES "employees"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "employee_placements" ADD CONSTRAINT "employee_placements_positionId_fkey" FOREIGN KEY ("positionId") REFERENCES "positions"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "employee_placements" ADD CONSTRAINT "employee_placements_branchId_fkey" FOREIGN KEY ("branchId") REFERENCES "branches"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "employee_placements" ADD CONSTRAINT "employee_placements_supervisorId_fkey" FOREIGN KEY ("supervisorId") REFERENCES "employees"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "kpi_rating_bands" ADD CONSTRAINT "kpi_rating_bands_ratingSchemeId_fkey" FOREIGN KEY ("ratingSchemeId") REFERENCES "kpi_rating_schemes"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "kpi_templates" ADD CONSTRAINT "kpi_templates_positionId_fkey" FOREIGN KEY ("positionId") REFERENCES "positions"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "kpi_template_versions" ADD CONSTRAINT "kpi_template_versions_kpiTemplateId_fkey" FOREIGN KEY ("kpiTemplateId") REFERENCES "kpi_templates"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "kpi_template_versions" ADD CONSTRAINT "kpi_template_versions_ratingSchemeId_fkey" FOREIGN KEY ("ratingSchemeId") REFERENCES "kpi_rating_schemes"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "kpi_template_versions" ADD CONSTRAINT "kpi_template_versions_activatedById_fkey" FOREIGN KEY ("activatedById") REFERENCES "users"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "kpi_template_items" ADD CONSTRAINT "kpi_template_items_templateVersionId_fkey" FOREIGN KEY ("templateVersionId") REFERENCES "kpi_template_versions"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "kpi_template_items" ADD CONSTRAINT "kpi_template_items_kpiDefinitionId_fkey" FOREIGN KEY ("kpiDefinitionId") REFERENCES "kpi_definitions"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "kpi_rubrics" ADD CONSTRAINT "kpi_rubrics_templateItemId_fkey" FOREIGN KEY ("templateItemId") REFERENCES "kpi_template_items"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "kpi_rubric_criteria" ADD CONSTRAINT "kpi_rubric_criteria_rubricId_fkey" FOREIGN KEY ("rubricId") REFERENCES "kpi_rubrics"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "kpi_periods" ADD CONSTRAINT "kpi_periods_createdById_fkey" FOREIGN KEY ("createdById") REFERENCES "users"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "kpi_period_branches" ADD CONSTRAINT "kpi_period_branches_periodId_fkey" FOREIGN KEY ("periodId") REFERENCES "kpi_periods"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "kpi_period_branches" ADD CONSTRAINT "kpi_period_branches_branchId_fkey" FOREIGN KEY ("branchId") REFERENCES "branches"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "employee_kpis" ADD CONSTRAINT "employee_kpis_periodId_fkey" FOREIGN KEY ("periodId") REFERENCES "kpi_periods"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "employee_kpis" ADD CONSTRAINT "employee_kpis_employeeId_fkey" FOREIGN KEY ("employeeId") REFERENCES "employees"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "employee_kpis" ADD CONSTRAINT "employee_kpis_templateVersionId_fkey" FOREIGN KEY ("templateVersionId") REFERENCES "kpi_template_versions"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "employee_kpis" ADD CONSTRAINT "employee_kpis_supervisorIdSnapshot_fkey" FOREIGN KEY ("supervisorIdSnapshot") REFERENCES "employees"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "employee_kpis" ADD CONSTRAINT "employee_kpis_managerIdSnapshot_fkey" FOREIGN KEY ("managerIdSnapshot") REFERENCES "employees"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "employee_kpis" ADD CONSTRAINT "employee_kpis_branchIdSnapshot_fkey" FOREIGN KEY ("branchIdSnapshot") REFERENCES "branches"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "employee_kpis" ADD CONSTRAINT "employee_kpis_positionIdSnapshot_fkey" FOREIGN KEY ("positionIdSnapshot") REFERENCES "positions"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "employee_kpis" ADD CONSTRAINT "employee_kpis_placementIdSnapshot_fkey" FOREIGN KEY ("placementIdSnapshot") REFERENCES "employee_placements"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "employee_kpi_items" ADD CONSTRAINT "employee_kpi_items_employeeKpiId_fkey" FOREIGN KEY ("employeeKpiId") REFERENCES "employee_kpis"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "employee_kpi_items" ADD CONSTRAINT "employee_kpi_items_kpiDefinitionId_fkey" FOREIGN KEY ("kpiDefinitionId") REFERENCES "kpi_definitions"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "employee_kpi_items" ADD CONSTRAINT "employee_kpi_items_managerDecidedById_fkey" FOREIGN KEY ("managerDecidedById") REFERENCES "users"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "kpi_daily_entries" ADD CONSTRAINT "kpi_daily_entries_employeeKpiItemId_fkey" FOREIGN KEY ("employeeKpiItemId") REFERENCES "employee_kpi_items"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "kpi_daily_entries" ADD CONSTRAINT "kpi_daily_entries_employeeEnteredById_fkey" FOREIGN KEY ("employeeEnteredById") REFERENCES "users"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "kpi_daily_entries" ADD CONSTRAINT "kpi_daily_entries_supervisorAssessedById_fkey" FOREIGN KEY ("supervisorAssessedById") REFERENCES "users"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "kpi_daily_entries" ADD CONSTRAINT "kpi_daily_entries_managerAssessedById_fkey" FOREIGN KEY ("managerAssessedById") REFERENCES "users"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "kpi_actual_entries" ADD CONSTRAINT "kpi_actual_entries_employeeKpiItemId_fkey" FOREIGN KEY ("employeeKpiItemId") REFERENCES "employee_kpi_items"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "kpi_actual_entries" ADD CONSTRAINT "kpi_actual_entries_inputById_fkey" FOREIGN KEY ("inputById") REFERENCES "users"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "kpi_evidences" ADD CONSTRAINT "kpi_evidences_employeeKpiItemId_fkey" FOREIGN KEY ("employeeKpiItemId") REFERENCES "employee_kpi_items"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "kpi_evidences" ADD CONSTRAINT "kpi_evidences_uploadedById_fkey" FOREIGN KEY ("uploadedById") REFERENCES "users"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "kpi_reviews" ADD CONSTRAINT "kpi_reviews_employeeKpiId_fkey" FOREIGN KEY ("employeeKpiId") REFERENCES "employee_kpis"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "kpi_reviews" ADD CONSTRAINT "kpi_reviews_reviewerId_fkey" FOREIGN KEY ("reviewerId") REFERENCES "users"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "kpi_review_items" ADD CONSTRAINT "kpi_review_items_kpiReviewId_fkey" FOREIGN KEY ("kpiReviewId") REFERENCES "kpi_reviews"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "kpi_review_items" ADD CONSTRAINT "kpi_review_items_employeeKpiItemId_fkey" FOREIGN KEY ("employeeKpiItemId") REFERENCES "employee_kpi_items"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "kpi_assessments" ADD CONSTRAINT "kpi_assessments_employeeKpiItemId_fkey" FOREIGN KEY ("employeeKpiItemId") REFERENCES "employee_kpi_items"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "kpi_assessments" ADD CONSTRAINT "kpi_assessments_kpiReviewId_fkey" FOREIGN KEY ("kpiReviewId") REFERENCES "kpi_reviews"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "kpi_assessments" ADD CONSTRAINT "kpi_assessments_assessedById_fkey" FOREIGN KEY ("assessedById") REFERENCES "users"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "kpi_assessment_answers" ADD CONSTRAINT "kpi_assessment_answers_kpiAssessmentId_fkey" FOREIGN KEY ("kpiAssessmentId") REFERENCES "kpi_assessments"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "kpi_approvals" ADD CONSTRAINT "kpi_approvals_employeeKpiId_fkey" FOREIGN KEY ("employeeKpiId") REFERENCES "employee_kpis"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "kpi_approvals" ADD CONSTRAINT "kpi_approvals_approverId_fkey" FOREIGN KEY ("approverId") REFERENCES "users"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "kpi_correction_requests" ADD CONSTRAINT "kpi_correction_requests_employeeKpiId_fkey" FOREIGN KEY ("employeeKpiId") REFERENCES "employee_kpis"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "kpi_correction_requests" ADD CONSTRAINT "kpi_correction_requests_requestedById_fkey" FOREIGN KEY ("requestedById") REFERENCES "users"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "kpi_correction_requests" ADD CONSTRAINT "kpi_correction_requests_approvedById_fkey" FOREIGN KEY ("approvedById") REFERENCES "users"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "kpi_calculation_runs" ADD CONSTRAINT "kpi_calculation_runs_employeeKpiId_fkey" FOREIGN KEY ("employeeKpiId") REFERENCES "employee_kpis"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "kpi_calculation_runs" ADD CONSTRAINT "kpi_calculation_runs_calculatedById_fkey" FOREIGN KEY ("calculatedById") REFERENCES "users"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "spareparts" ADD CONSTRAINT "spareparts_branchId_fkey" FOREIGN KEY ("branchId") REFERENCES "branches"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "service_tickets" ADD CONSTRAINT "service_tickets_branchId_fkey" FOREIGN KEY ("branchId") REFERENCES "branches"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "service_tickets" ADD CONSTRAINT "service_tickets_periodId_fkey" FOREIGN KEY ("periodId") REFERENCES "kpi_periods"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "service_tickets" ADD CONSTRAINT "service_tickets_intakeByEmployeeId_fkey" FOREIGN KEY ("intakeByEmployeeId") REFERENCES "employees"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "service_tickets" ADD CONSTRAINT "service_tickets_cashierEmployeeId_fkey" FOREIGN KEY ("cashierEmployeeId") REFERENCES "employees"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "service_tickets" ADD CONSTRAINT "service_tickets_technicianEmployeeId_fkey" FOREIGN KEY ("technicianEmployeeId") REFERENCES "employees"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "service_tickets" ADD CONSTRAINT "service_tickets_customerConsentByEmployeeId_fkey" FOREIGN KEY ("customerConsentByEmployeeId") REFERENCES "employees"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "service_tickets" ADD CONSTRAINT "service_tickets_paymentRecordedByEmployeeId_fkey" FOREIGN KEY ("paymentRecordedByEmployeeId") REFERENCES "employees"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "service_tickets" ADD CONSTRAINT "service_tickets_deliveredByEmployeeId_fkey" FOREIGN KEY ("deliveredByEmployeeId") REFERENCES "employees"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "service_tickets" ADD CONSTRAINT "service_tickets_paymentExceptionApprovedByUserId_fkey" FOREIGN KEY ("paymentExceptionApprovedByUserId") REFERENCES "users"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "service_tickets" ADD CONSTRAINT "service_tickets_warrantyReviewedByUserId_fkey" FOREIGN KEY ("warrantyReviewedByUserId") REFERENCES "users"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "service_tickets" ADD CONSTRAINT "service_tickets_warrantyReturnedFromTicketId_fkey" FOREIGN KEY ("warrantyReturnedFromTicketId") REFERENCES "service_tickets"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "sparepart_requests" ADD CONSTRAINT "sparepart_requests_serviceTicketId_fkey" FOREIGN KEY ("serviceTicketId") REFERENCES "service_tickets"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "sparepart_requests" ADD CONSTRAINT "sparepart_requests_sparepartId_fkey" FOREIGN KEY ("sparepartId") REFERENCES "spareparts"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "sparepart_requests" ADD CONSTRAINT "sparepart_requests_technicianEmployeeId_fkey" FOREIGN KEY ("technicianEmployeeId") REFERENCES "employees"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "sparepart_requests" ADD CONSTRAINT "sparepart_requests_warehouseEmployeeId_fkey" FOREIGN KEY ("warehouseEmployeeId") REFERENCES "employees"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "sparepart_requests" ADD CONSTRAINT "sparepart_requests_confirmedByEmployeeId_fkey" FOREIGN KEY ("confirmedByEmployeeId") REFERENCES "employees"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "stock_movements" ADD CONSTRAINT "stock_movements_sparepartId_fkey" FOREIGN KEY ("sparepartId") REFERENCES "spareparts"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "stock_movements" ADD CONSTRAINT "stock_movements_userId_fkey" FOREIGN KEY ("userId") REFERENCES "users"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "stock_opnames" ADD CONSTRAINT "stock_opnames_periodId_fkey" FOREIGN KEY ("periodId") REFERENCES "kpi_periods"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "stock_opnames" ADD CONSTRAINT "stock_opnames_branchId_fkey" FOREIGN KEY ("branchId") REFERENCES "branches"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "stock_opnames" ADD CONSTRAINT "stock_opnames_createdById_fkey" FOREIGN KEY ("createdById") REFERENCES "users"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "stock_opname_items" ADD CONSTRAINT "stock_opname_items_stockOpnameId_fkey" FOREIGN KEY ("stockOpnameId") REFERENCES "stock_opnames"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "stock_opname_items" ADD CONSTRAINT "stock_opname_items_sparepartId_fkey" FOREIGN KEY ("sparepartId") REFERENCES "spareparts"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "attendances" ADD CONSTRAINT "attendances_employeeId_fkey" FOREIGN KEY ("employeeId") REFERENCES "employees"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "attendances" ADD CONSTRAINT "attendances_branchId_fkey" FOREIGN KEY ("branchId") REFERENCES "branches"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "attendances" ADD CONSTRAINT "attendances_recordedById_fkey" FOREIGN KEY ("recordedById") REFERENCES "users"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "admin_work_logs" ADD CONSTRAINT "admin_work_logs_employeeId_fkey" FOREIGN KEY ("employeeId") REFERENCES "employees"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "admin_work_logs" ADD CONSTRAINT "admin_work_logs_periodId_fkey" FOREIGN KEY ("periodId") REFERENCES "kpi_periods"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "admin_work_logs" ADD CONSTRAINT "admin_work_logs_recordedById_fkey" FOREIGN KEY ("recordedById") REFERENCES "users"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "complaints" ADD CONSTRAINT "complaints_employeeId_fkey" FOREIGN KEY ("employeeId") REFERENCES "employees"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "complaints" ADD CONSTRAINT "complaints_serviceTicketId_fkey" FOREIGN KEY ("serviceTicketId") REFERENCES "service_tickets"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "complaints" ADD CONSTRAINT "complaints_recordedById_fkey" FOREIGN KEY ("recordedById") REFERENCES "users"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "coaching_logs" ADD CONSTRAINT "coaching_logs_supervisorId_fkey" FOREIGN KEY ("supervisorId") REFERENCES "employees"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "coaching_logs" ADD CONSTRAINT "coaching_logs_employeeId_fkey" FOREIGN KEY ("employeeId") REFERENCES "employees"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "coaching_logs" ADD CONSTRAINT "coaching_logs_periodId_fkey" FOREIGN KEY ("periodId") REFERENCES "kpi_periods"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "coaching_logs" ADD CONSTRAINT "coaching_logs_recordedById_fkey" FOREIGN KEY ("recordedById") REFERENCES "users"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "customer_feedbacks" ADD CONSTRAINT "customer_feedbacks_serviceTicketId_fkey" FOREIGN KEY ("serviceTicketId") REFERENCES "service_tickets"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "customer_feedbacks" ADD CONSTRAINT "customer_feedbacks_csEmployeeId_fkey" FOREIGN KEY ("csEmployeeId") REFERENCES "employees"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "customer_feedbacks" ADD CONSTRAINT "customer_feedbacks_technicianEmployeeId_fkey" FOREIGN KEY ("technicianEmployeeId") REFERENCES "employees"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "feedback_follow_ups" ADD CONSTRAINT "feedback_follow_ups_customerFeedbackId_fkey" FOREIGN KEY ("customerFeedbackId") REFERENCES "customer_feedbacks"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "feedback_follow_ups" ADD CONSTRAINT "feedback_follow_ups_serviceTicketId_fkey" FOREIGN KEY ("serviceTicketId") REFERENCES "service_tickets"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "feedback_follow_ups" ADD CONSTRAINT "feedback_follow_ups_assignedEmployeeId_fkey" FOREIGN KEY ("assignedEmployeeId") REFERENCES "employees"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "feedback_follow_ups" ADD CONSTRAINT "feedback_follow_ups_assignedByUserId_fkey" FOREIGN KEY ("assignedByUserId") REFERENCES "users"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "feedback_follow_ups" ADD CONSTRAINT "feedback_follow_ups_completedByUserId_fkey" FOREIGN KEY ("completedByUserId") REFERENCES "users"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "import_mapping_versions" ADD CONSTRAINT "import_mapping_versions_mappingTemplateId_fkey" FOREIGN KEY ("mappingTemplateId") REFERENCES "import_mapping_templates"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "import_batches" ADD CONSTRAINT "import_batches_mappingVersionId_fkey" FOREIGN KEY ("mappingVersionId") REFERENCES "import_mapping_versions"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "import_batches" ADD CONSTRAINT "import_batches_periodId_fkey" FOREIGN KEY ("periodId") REFERENCES "kpi_periods"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "import_batches" ADD CONSTRAINT "import_batches_branchId_fkey" FOREIGN KEY ("branchId") REFERENCES "branches"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "import_batches" ADD CONSTRAINT "import_batches_uploaderId_fkey" FOREIGN KEY ("uploaderId") REFERENCES "users"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "import_batches" ADD CONSTRAINT "import_batches_confirmedById_fkey" FOREIGN KEY ("confirmedById") REFERENCES "users"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "import_batches" ADD CONSTRAINT "import_batches_supersededById_fkey" FOREIGN KEY ("supersededById") REFERENCES "import_batches"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "cashier_transactions" ADD CONSTRAINT "cashier_transactions_importBatchId_fkey" FOREIGN KEY ("importBatchId") REFERENCES "import_batches"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "cashier_transactions" ADD CONSTRAINT "cashier_transactions_periodId_fkey" FOREIGN KEY ("periodId") REFERENCES "kpi_periods"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "cashier_transactions" ADD CONSTRAINT "cashier_transactions_cashierEmployeeId_fkey" FOREIGN KEY ("cashierEmployeeId") REFERENCES "employees"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "system_notifications" ADD CONSTRAINT "system_notifications_userId_fkey" FOREIGN KEY ("userId") REFERENCES "users"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "audit_events" ADD CONSTRAINT "audit_events_actorId_fkey" FOREIGN KEY ("actorId") REFERENCES "users"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "report_submissions" ADD CONSTRAINT "report_submissions_periodId_fkey" FOREIGN KEY ("periodId") REFERENCES "kpi_periods"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "report_submissions" ADD CONSTRAINT "report_submissions_employeeId_fkey" FOREIGN KEY ("employeeId") REFERENCES "employees"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "report_submissions" ADD CONSTRAINT "report_submissions_submittedById_fkey" FOREIGN KEY ("submittedById") REFERENCES "users"("id") ON DELETE SET NULL ON UPDATE CASCADE;
