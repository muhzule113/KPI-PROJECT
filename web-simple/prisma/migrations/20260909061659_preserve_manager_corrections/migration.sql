-- AlterTable
ALTER TABLE "daily_sheets" ADD COLUMN     "effectiveWorkStatus" "WorkStatus",
ADD COLUMN     "managerWorkStatus" "WorkStatus";

-- AlterTable
ALTER TABLE "monthly_kpis" ADD COLUMN     "revisionNumber" INTEGER NOT NULL DEFAULT 0;
