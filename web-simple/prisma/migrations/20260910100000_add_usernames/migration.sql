-- Expand first so existing rows remain valid until the data backfill completes.
ALTER TABLE "users" ADD COLUMN "username" TEXT;
