import assert from "node:assert/strict";
import { randomUUID } from "node:crypto";
import { unlink } from "node:fs/promises";
import { join } from "node:path";
import { tmpdir } from "node:os";
import { databaseConfig, databaseCounts, runPostgresTool } from "./postgres-tools";

const config = databaseConfig();
const suffix = randomUUID().replaceAll("-", "").slice(0, 16);
const restoredDatabase = `kpi_restore_drill_${suffix}`;
const dump = join(tmpdir(), `${restoredDatabase}.dump`);

try {
  await runPostgresTool("pg_dump", ["--format=custom", "--no-owner", "--no-privileges", "--file", dump], config.database);
  await runPostgresTool("createdb", ["--template", "template0", restoredDatabase]);
  await runPostgresTool("pg_restore", ["--exit-on-error", "--no-owner", "--no-privileges", dump], restoredDatabase);
  const [source, restored] = await Promise.all([databaseCounts(config.database), databaseCounts(restoredDatabase)]);
  assert.deepEqual(restored, source, "Jumlah baris tabel inti berbeda setelah restore.");
  process.stdout.write(`Restore PostgreSQL terverifikasi: ${JSON.stringify(restored)}\n`);
} finally {
  await runPostgresTool("dropdb", ["--if-exists", restoredDatabase]).catch(() => undefined);
  await unlink(dump).catch(() => undefined);
}
