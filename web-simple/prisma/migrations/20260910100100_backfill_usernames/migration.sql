-- Forward-only data migration: derive usernames before replacing legacy email values.
DO $$
DECLARE
  user_record RECORD;
  base_username TEXT;
  candidate TEXT;
  suffix_number INTEGER;
  suffix_text TEXT;
BEGIN
  FOR user_record IN
    SELECT "id", "email"
    FROM "users"
    ORDER BY "createdAt", "id"
  LOOP
    base_username := lower(split_part(user_record."email", '@', 1));
    base_username := regexp_replace(base_username, '[^a-z0-9._-]+', '-', 'g');
    base_username := regexp_replace(base_username, '^[._-]+|[._-]+$', '', 'g');
    base_username := regexp_replace(left(base_username, 50), '[._-]+$', '', 'g');

    IF char_length(base_username) < 3 THEN
      base_username := 'user-' || left(replace(user_record."id", '-', ''), 12);
    END IF;

    candidate := base_username;
    suffix_number := 2;
    WHILE EXISTS (SELECT 1 FROM "users" WHERE "username" = candidate) LOOP
      suffix_text := '_' || suffix_number::text;
      candidate := regexp_replace(left(base_username, 50 - char_length(suffix_text)), '[._-]+$', '', 'g') || suffix_text;
      suffix_number := suffix_number + 1;
    END LOOP;

    UPDATE "users" SET "username" = candidate WHERE "id" = user_record."id";
  END LOOP;
END $$;

-- Better Auth requires an email field, but this product no longer treats it as user data.
UPDATE "users" SET "email" = "id" || '@users.kpi.invalid';

-- Remove legacy email values from account audit snapshots.
UPDATE "audit_events" SET "beforeJson" = "beforeJson" - 'email' WHERE "beforeJson" ? 'email';
UPDATE "audit_events" SET "afterJson" = "afterJson" - 'email' WHERE "afterJson" ? 'email';
