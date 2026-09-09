import "dotenv/config";

import assert from "node:assert/strict";
import { randomUUID } from "node:crypto";
import { readFile } from "node:fs/promises";
import { Client } from "pg";

const connectionString = process.env.DATABASE_URL;
if (!connectionString) throw new Error("DATABASE_URL wajib diisi.");

const schema = `verify_username_${randomUUID().replaceAll("-", "")}`;
const client = new Client({ connectionString });
const migrations = [
  "../prisma/migrations/20260910100000_add_usernames/migration.sql",
  "../prisma/migrations/20260910100100_backfill_usernames/migration.sql",
  "../prisma/migrations/20260910100200_require_usernames_remove_employee_email/migration.sql",
];

await client.connect();
try {
  await client.query(`CREATE SCHEMA "${schema}"`);
  await client.query(`SET search_path TO "${schema}"`);
  await client.query(`
    CREATE TABLE "users" (
      "id" TEXT PRIMARY KEY,
      "email" TEXT NOT NULL UNIQUE,
      "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE "employees" ("id" TEXT PRIMARY KEY, "email" TEXT NOT NULL);
    CREATE UNIQUE INDEX "employees_email_key" ON "employees"("email");
    CREATE TABLE "audit_events" ("id" TEXT PRIMARY KEY, "beforeJson" JSONB, "afterJson" JSONB);
    INSERT INTO "users" ("id", "email") VALUES
      ('u1', 'Same@example.com'),
      ('u2', 'same@other.test'),
      ('u3', 'a@example.com'),
      ('u4', '-bad+name-@example.com'),
      ('u5', 'same_2@example.com');
    INSERT INTO "employees" ("id", "email") VALUES ('e1', 'employee@example.com');
    INSERT INTO "audit_events" ("id", "beforeJson", "afterJson")
      VALUES ('a1', '{"email":"before@example.com","name":"Sebelum"}', '{"email":"after@example.com","name":"Sesudah"}');
  `);

  for (const migration of migrations) {
    await client.query(await readFile(new URL(migration, import.meta.url), "utf8"));
  }

  const users = await client.query<{ id: string; username: string; email: string }>(`SELECT "id", "username", "email" FROM "users" ORDER BY "id"`);
  assert.deepEqual(users.rows, [
    { id: "u1", username: "same", email: "u1@users.kpi.invalid" },
    { id: "u2", username: "same_2", email: "u2@users.kpi.invalid" },
    { id: "u3", username: "user-u3", email: "u3@users.kpi.invalid" },
    { id: "u4", username: "bad-name", email: "u4@users.kpi.invalid" },
    { id: "u5", username: "same_2_2", email: "u5@users.kpi.invalid" },
  ]);

  const employeeEmail = await client.query<{ count: number }>(`
    SELECT count(*)::int AS "count"
    FROM information_schema.columns
    WHERE table_schema = $1 AND table_name = 'employees' AND column_name = 'email'
  `, [schema]);
  assert.equal(employeeEmail.rows[0].count, 0);

  const audit = await client.query<{ before_has_email: boolean; after_has_email: boolean }>(`
    SELECT "beforeJson" ? 'email' AS "before_has_email", "afterJson" ? 'email' AS "after_has_email"
    FROM "audit_events" WHERE "id" = 'a1'
  `);
  assert.deepEqual(audit.rows[0], { before_has_email: false, after_has_email: false });
  await assert.rejects(
    client.query(`INSERT INTO "users" ("id", "username", "email") VALUES ('invalid', 'Uppercase', 'invalid@users.kpi.invalid')`),
  );
} finally {
  await client.query("SET search_path TO public");
  await client.query(`DROP SCHEMA IF EXISTS "${schema}" CASCADE`);
  await client.end();
}

console.info("Verifikasi migrasi username lulus: normalisasi, fallback, konflik, constraint, profil, dan audit.");
