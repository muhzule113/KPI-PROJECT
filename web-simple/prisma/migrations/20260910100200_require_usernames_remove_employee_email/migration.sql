-- Contract after the username backfill. Restoring removed email data requires a pre-deploy backup.
ALTER TABLE "users"
  ADD CONSTRAINT "users_username_format_check"
  CHECK (
    "username" IS NOT NULL
    AND "username" ~ '^[a-z0-9][a-z0-9._-]{1,48}[a-z0-9]$'
  ) NOT VALID;

ALTER TABLE "users" VALIDATE CONSTRAINT "users_username_format_check";
ALTER TABLE "users" ALTER COLUMN "username" SET NOT NULL;
CREATE UNIQUE INDEX "users_username_key" ON "users"("username");

DROP INDEX "employees_email_key";
ALTER TABLE "employees" DROP COLUMN "email";
