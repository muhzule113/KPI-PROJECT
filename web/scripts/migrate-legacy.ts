import "dotenv/config";
import { resolve } from "node:path";
import { pathToFileURL } from "node:url";
import { config } from "dotenv";
import mysql, { type ConnectionOptions, type RowDataPacket } from "mysql2/promise";
import { Client } from "pg";

type LegacyRow = RowDataPacket & Record<string, unknown>;
type TargetColumn = {
  name: string;
  dataType: string;
  udtName: string;
  nullable: "YES" | "NO";
  defaultValue: string | null;
};
type DeferredValue = { table: string; id: string; column: TargetColumn; value: unknown };

const TABLES = [
  "branches", "positions", "employees", "employee_placements",
  "kpi_rating_schemes", "kpi_rating_bands", "kpi_definitions", "kpi_templates",
  "kpi_template_versions", "kpi_template_items", "kpi_rubrics", "kpi_rubric_criteria",
  "kpi_periods", "kpi_period_branches", "employee_kpis", "employee_kpi_items",
  "kpi_daily_entries", "kpi_actual_entries", "kpi_evidences", "kpi_reviews",
  "kpi_review_items", "kpi_assessments", "kpi_assessment_answers", "kpi_approvals",
  "kpi_correction_requests", "kpi_calculation_runs", "spareparts", "service_tickets",
  "sparepart_requests", "stock_movements", "stock_opnames", "stock_opname_items",
  "attendances", "admin_work_logs", "complaints", "coaching_logs", "customer_feedbacks",
  "feedback_follow_ups", "import_mapping_templates", "import_mapping_versions", "import_batches",
  "cashier_transactions", "system_notifications", "audit_events", "report_submissions",
] as const;

const DEFERRED_SOURCE_COLUMNS: Record<string, Set<string>> = {
  employees: new Set(["supervisor_id"]),
  service_tickets: new Set(["warranty_returned_from_ticket_id"]),
  import_batches: new Set(["superseded_by_id"]),
};

const COLUMN_OVERRIDES: Record<string, Record<string, string>> = {
  employee_kpi_items: { evidence_req_snapshot: "evidenceRequiredSnapshot" },
};

const SUBJECTIVE_SOURCES: Record<string, string> = {
  "TEK-01": "system", "TEK-02": "system", "TEK-03": "cross_role", "TEK-04": "system", "TEK-07": "system",
  "CS-01": "system", "CS-03": "system", "CS-04": "system", "CS-05": "cross_role",
  "ADM-01": "system", "ADM-02": "system", "ADM-03": "system", "ADM-04": "system",
  "KSR-01": "import", "KSR-02": "import", "KSR-03": "import", "KSR-04": "import",
  "GUD-01": "system", "GUD-02": "system", "GUD-03": "system", "GUD-04": "system", "GUD-05": "system",
  "TEK-05": "supervisor", "TEK-06": "supervisor", "CS-02": "supervisor",
  "ADM-06": "supervisor", "KSR-05": "supervisor", "GUD-06": "supervisor",
  "CS-06": "supervisor", "ADM-05": "supervisor", "KSR-06": "supervisor", "GUD-07": "supervisor",
};
const SUBJECTIVE_CODES = new Set(["TEK-05", "TEK-06", "CS-02", "ADM-06", "KSR-05", "GUD-06"]);
const SUBJECTIVE_TEMPLATES = ["TPL-TEK-01", "TPL-CS-01", "TPL-ADM-01", "TPL-KSR-01", "TPL-GUD-01"];
const MIGRATION_MARKER = "legacy:2026_09_07_200000_version_subjective_kpi_predicates";

export function snakeToCamel(value: string) {
  return value.replace(/_([a-z0-9])/g, (_, letter: string) => letter.toUpperCase());
}

export function targetColumnName(sourceColumn: string, targetNames: Set<string>, override?: string) {
  if (override && targetNames.has(override)) return override;
  const direct = snakeToCamel(sourceColumn);
  if (targetNames.has(direct)) return direct;
  const actor = sourceColumn.endsWith("_by") ? `${direct}Id` : "";
  return actor && targetNames.has(actor) ? actor : undefined;
}

export function coerceForTarget(value: unknown, column: Pick<TargetColumn, "dataType" | "udtName" | "name">) {
  if (value == null) return null;
  if (column.dataType === "boolean") return value === true || value === 1 || value === "1";
  if (column.dataType === "json" || column.dataType === "jsonb") {
    if (Buffer.isBuffer(value)) value = value.toString("utf8");
    return typeof value === "string" ? JSON.parse(value) : value;
  }
  if (column.dataType === "bigint") return String(value);
  if (["smallint", "integer"].includes(column.dataType)) return Number(value);
  if (column.dataType === "numeric") return String(value);
  if (["character varying", "character", "text"].includes(column.dataType) || column.dataType === "USER-DEFINED") return String(value);
  return value;
}

function quote(identifier: string) {
  return `"${identifier.replaceAll('"', '""')}"`;
}

function legacyConnectionOptions(): ConnectionOptions {
  if (process.env.LEGACY_ENV_FILE) config({ path: process.env.LEGACY_ENV_FILE, override: false, quiet: true });
  const urlValue = process.env.LEGACY_DATABASE_URL;
  const common = { dateStrings: true, supportBigNumbers: true, bigNumberStrings: true } as const;
  if (urlValue) {
    const url = new URL(urlValue);
    if (url.protocol !== "mysql:") throw new Error("LEGACY_DATABASE_URL wajib memakai protokol mysql://.");
    return { ...common, host: url.hostname, port: Number(url.port || 3306), user: decodeURIComponent(url.username), password: decodeURIComponent(url.password), database: decodeURIComponent(url.pathname.slice(1)) };
  }
  const host = process.env.LEGACY_DB_HOST ?? process.env.DB_HOST;
  const database = process.env.LEGACY_DB_DATABASE ?? process.env.DB_DATABASE;
  const user = process.env.LEGACY_DB_USERNAME ?? process.env.DB_USERNAME;
  if (!host || !database || !user) throw new Error("Isi LEGACY_DATABASE_URL atau LEGACY_ENV_FILE sebelum migrasi.");
  return { ...common, host, port: Number(process.env.LEGACY_DB_PORT ?? process.env.DB_PORT ?? 3306), user, password: process.env.LEGACY_DB_PASSWORD ?? process.env.DB_PASSWORD, database };
}

function nextMonthStart() {
  const parts = new Intl.DateTimeFormat("en-CA", { timeZone: "Asia/Makassar", year: "numeric", month: "2-digit" }).formatToParts(new Date());
  const year = Number(parts.find((part) => part.type === "year")?.value);
  const month = Number(parts.find((part) => part.type === "month")?.value);
  return new Date(Date.UTC(year, month, 1)).toISOString().slice(0, 10);
}

function migrationEffectiveDate() {
  const value = process.env.MIGRATION_EFFECTIVE_DATE || nextMonthStart();
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value) || Number.isNaN(Date.parse(`${value}T00:00:00Z`))) throw new Error("MIGRATION_EFFECTIVE_DATE harus berformat YYYY-MM-DD.");
  return value;
}

async function sourceRows(source: mysql.Connection, table: string) {
  if (table === "users") {
    const [rows] = await source.query<LegacyRow[]>(`
      SELECT u.*,
        COALESCE((SELECT r.name FROM model_has_roles mr JOIN roles r ON r.id = mr.role_id WHERE mr.model_id = u.id AND mr.model_type LIKE '%User' LIMIT 1), 'employee') AS legacy_role
      FROM users u ORDER BY u.id
    `);
    return rows;
  }
  const [rows] = await source.query<LegacyRow[]>("SELECT * FROM ?? ORDER BY id", [table]);
  return rows;
}

class TargetWriter {
  private readonly columns = new Map<string, Map<string, TargetColumn>>();
  private readonly client: Client;

  constructor(client: Client) {
    this.client = client;
  }

  async tableColumns(table: string) {
    const cached = this.columns.get(table);
    if (cached) return cached;
    const result = await this.client.query(`
      SELECT column_name AS name, data_type AS "dataType", udt_name AS "udtName",
             is_nullable AS nullable, column_default AS "defaultValue"
      FROM information_schema.columns
      WHERE table_schema = 'public' AND table_name = $1
      ORDER BY ordinal_position
    `, [table]);
    if (!result.rows.length) throw new Error(`Tabel PostgreSQL ${table} belum tersedia. Jalankan prisma migrate deploy.`);
    const mapped = new Map((result.rows as TargetColumn[]).map((column) => [column.name, column]));
    this.columns.set(table, mapped);
    return mapped;
  }

  async mapLegacyRow(table: string, source: LegacyRow, deferred: DeferredValue[]) {
    const columns = await this.tableColumns(table);
    const names = new Set(columns.keys());
    const row: Record<string, unknown> = {};
    for (const [sourceName, raw] of Object.entries(source)) {
      const columnName = targetColumnName(sourceName, names, COLUMN_OVERRIDES[table]?.[sourceName]);
      if (!columnName) continue;
      const column = columns.get(columnName)!;
      if (DEFERRED_SOURCE_COLUMNS[table]?.has(sourceName)) {
        if (raw != null) deferred.push({ table, id: String(source.id), column, value: raw });
        continue;
      }
      const value = coerceForTarget(raw, column);
      if (value == null && column.nullable === "NO" && column.defaultValue != null) continue;
      row[columnName] = value;
    }
    if (row.updatedAt == null && columns.get("updatedAt")?.nullable === "NO") row.updatedAt = row.createdAt ?? new Date(0);
    return this.validate(table, row);
  }

  async targetRow(table: string, values: Record<string, unknown>) {
    const columns = await this.tableColumns(table);
    const row: Record<string, unknown> = {};
    for (const [name, raw] of Object.entries(values)) {
      const column = columns.get(name);
      if (column) row[name] = coerceForTarget(raw, column);
    }
    return this.validate(table, row);
  }

  private validate(table: string, row: Record<string, unknown>) {
    const columns = this.columns.get(table)!;
    for (const column of columns.values()) {
      if (column.nullable === "NO" && column.defaultValue == null && row[column.name] == null) {
        throw new Error(`${table} id=${String(row.id ?? "?")}: kolom wajib ${column.name} tidak memiliki sumber data.`);
      }
    }
    return row;
  }

  async upsert(table: string, values: Record<string, unknown>) {
    const row = await this.targetRow(table, values);
    const columns = await this.tableColumns(table);
    const names = Object.keys(row);
    const updates = names.filter((name) => name !== "id");
    const conflict = updates.length
      ? `DO UPDATE SET ${updates.map((name) => `${quote(name)} = EXCLUDED.${quote(name)}`).join(", ")}`
      : "DO NOTHING";
    try {
      await this.client.query(
        `INSERT INTO ${quote(table)} (${names.map(quote).join(", ")}) VALUES (${names.map((_, index) => `$${index + 1}`).join(", ")}) ON CONFLICT (${quote("id")}) ${conflict}`,
        names.map((name) => {
          const value = row[name];
          const column = columns.get(name)!;
          return value != null && (column.dataType === "json" || column.dataType === "jsonb") ? JSON.stringify(value) : value;
        }),
      );
    } catch (error) {
      const reason = error instanceof Error ? error.message : String(error);
      throw new Error(`Gagal menulis ${table} id=${String(row.id)}: ${reason}`);
    }
  }

  async updateDeferred(values: DeferredValue[]) {
    for (const value of values) {
      await this.client.query(`UPDATE ${quote(value.table)} SET ${quote(value.column.name)} = $1 WHERE ${quote("id")} = $2`, [coerceForTarget(value.value, value.column), value.id]);
    }
  }
}

function userRow(source: LegacyRow) {
  const roles = new Set(["super_admin", "kpi_admin", "auditor", "owner_manager", "supervisor", "employee"]);
  const role = String(source.legacy_role);
  return {
    id: String(source.id),
    name: source.name,
    email: String(source.email).toLowerCase(),
    emailVerified: source.email_verified_at != null,
    isActive: source.is_active === 1 || source.is_active === "1" || source.is_active === true,
    role: roles.has(role) ? role : "employee",
    createdAt: source.created_at ?? new Date(0),
    updatedAt: source.updated_at ?? source.created_at ?? new Date(0),
  };
}

async function migrateUsers(source: mysql.Connection, writer: TargetWriter, apply: boolean) {
  const users = await sourceRows(source, "users");
  for (const user of users) {
    const mapped = await writer.targetRow("users", userRow(user));
    const account = await writer.targetRow("accounts", {
      id: `legacy-account-${String(user.id)}`,
      accountId: String(user.id),
      providerId: "credential",
      userId: String(user.id),
      password: user.password,
      createdAt: user.created_at ?? new Date(0),
      updatedAt: user.updated_at ?? user.created_at ?? new Date(0),
    });
    if (apply) {
      await writer.upsert("users", mapped);
      await writer.upsert("accounts", account);
    }
  }
  return users;
}

async function sourceHasSubjectiveMigration(source: mysql.Connection) {
  const [rows] = await source.query<LegacyRow[]>("SELECT 1 present FROM migrations WHERE migration = ? LIMIT 1", ["2026_09_07_200000_version_subjective_kpi_predicates"]);
  return rows.length > 0;
}

async function applyPendingSubjectiveMigration(target: Client, writer: TargetWriter) {
  const schemeResult = await target.query(`SELECT * FROM kpi_rating_schemes WHERE name = $1 AND "isActive" = true AND id NOT LIKE 'legacy-subjective-scheme-%' ORDER BY version DESC LIMIT 1`, ["Skema Rating Standar 5-Tingkat"]);
  if (!schemeResult.rowCount) return;

  const scheme = schemeResult.rows[0];
  const maxResult = await target.query(`SELECT COALESCE(MAX(version), 0)::int AS version FROM kpi_rating_schemes WHERE name = $1 AND id NOT LIKE 'legacy-subjective-scheme-%'`, [scheme.name]);
  const version = Number(maxResult.rows[0].version) + 1;
  const now = new Date();
  const newSchemeId = `legacy-subjective-scheme-${String(scheme.id)}`;
  await writer.upsert("kpi_rating_schemes", { ...scheme, id: newSchemeId, version, description: "Lima predikat untuk penilaian subjektif Supervisor.", isDefault: scheme.isDefault, isActive: true, createdAt: now, updatedAt: now });
  const bands = await target.query(`SELECT * FROM kpi_rating_bands WHERE "ratingSchemeId" = $1 ORDER BY "sortOrder"`, [scheme.id]);
  const scores: Record<string, number> = { POOR: 60, FAIR: 75, GOOD: 85, VERY_GOOD: 95, STAR: 100 };
  for (const band of bands.rows) {
    await writer.upsert("kpi_rating_bands", { ...band, id: `legacy-subjective-band-${String(band.id)}`, ratingSchemeId: newSchemeId, label: band.code === "STAR" ? "Istimewa" : band.label, manualScore: scores[String(band.code)] ?? null, createdAt: now, updatedAt: now });
  }
  await target.query(`UPDATE kpi_rating_schemes SET "isActive" = false, "isDefault" = false, "updatedAt" = $1 WHERE name = $2 AND id <> $3`, [now, scheme.name, newSchemeId]);

  for (const [code, sourceType] of Object.entries(SUBJECTIVE_SOURCES)) {
    await target.query(`UPDATE kpi_definitions SET "sourceType" = $1, "metricType" = CASE WHEN code = 'CS-02' THEN 'rubric' ELSE "metricType" END, "defaultFormula" = CASE WHEN code = 'CS-02' THEN 'rubric' ELSE "defaultFormula" END, "updatedAt" = $2 WHERE code = $3`, [sourceType, now, code]);
  }

  const effectiveFrom = migrationEffectiveDate();
  const effectiveUntil = new Date(Date.parse(`${effectiveFrom}T00:00:00Z`) - 86_400_000).toISOString().slice(0, 10);
  const templates = await target.query(`SELECT * FROM kpi_templates WHERE code = ANY($1::text[])`, [SUBJECTIVE_TEMPLATES]);
  for (const template of templates.rows) {
    const activeResult = await target.query(`SELECT * FROM kpi_template_versions WHERE "kpiTemplateId" = $1 AND status = 'active' AND id NOT LIKE 'legacy-subjective-template-%' ORDER BY "versionNumber" DESC LIMIT 1`, [template.id]);
    if (!activeResult.rowCount) continue;
    const active = activeResult.rows[0];
    const versionResult = await target.query(`SELECT COALESCE(MAX("versionNumber"), 0)::int AS version FROM kpi_template_versions WHERE "kpiTemplateId" = $1 AND id NOT LIKE 'legacy-subjective-template-%'`, [template.id]);
    const newVersion = Number(versionResult.rows[0].version) + 1;
    const newVersionId = `legacy-subjective-template-${String(template.id)}-v${newVersion}`;
    await writer.upsert("kpi_template_versions", { ...active, id: newVersionId, versionNumber: newVersion, status: "active", ratingSchemeId: newSchemeId, checksum: null, effectiveFrom, effectiveUntil: null, activatedById: null, activatedAt: now, createdAt: now, updatedAt: now });

    const items = await target.query(`SELECT i.*, d.code AS "definitionCode" FROM kpi_template_items i JOIN kpi_definitions d ON d.id = i."kpiDefinitionId" WHERE i."templateVersionId" = $1 ORDER BY i."sortOrder"`, [active.id]);
    for (const item of items.rows) {
      const code = String(item.definitionCode);
      const itemId = `legacy-subjective-item-${String(item.id)}`;
      await writer.upsert("kpi_template_items", { ...item, id: itemId, templateVersionId: newVersionId, formulaKey: SUBJECTIVE_CODES.has(code) ? "rubric" : item.formulaKey, sourceType: SUBJECTIVE_SOURCES[code] ?? item.sourceType, createdAt: now, updatedAt: now });
      const rubricResult = await target.query(`SELECT * FROM kpi_rubrics WHERE "templateItemId" = $1 LIMIT 1`, [item.id]);
      if (rubricResult.rowCount) {
        const rubric = rubricResult.rows[0];
        const rubricId = `legacy-subjective-rubric-${String(rubric.id)}`;
        await writer.upsert("kpi_rubrics", { ...rubric, id: rubricId, templateItemId: itemId, createdAt: now, updatedAt: now });
        const criteria = await target.query(`SELECT * FROM kpi_rubric_criteria WHERE "rubricId" = $1 ORDER BY "sortOrder"`, [rubric.id]);
        for (const criterion of criteria.rows) await writer.upsert("kpi_rubric_criteria", { ...criterion, id: `legacy-subjective-criterion-${String(criterion.id)}`, rubricId, createdAt: now, updatedAt: now });
      } else if (code === "CS-02") {
        const rubricId = `legacy-subjective-rubric-cs02-${String(item.id)}`;
        await writer.upsert("kpi_rubrics", { id: rubricId, templateItemId: itemId, name: "Rubrik Kecepatan Melayani Pelanggan", description: "Panduan observasi; Supervisor tetap memilih satu predikat untuk keseluruhan KPI.", createdAt: now, updatedAt: now });
        const criteria = ["Kebutuhan pelanggan dikenali tanpa penundaan yang tidak perlu", "Proses dan perkiraan waktu layanan dijelaskan dengan jelas", "Pelayanan diselesaikan sesuai antrean dan standar toko"];
        for (const [index, text] of criteria.entries()) await writer.upsert("kpi_rubric_criteria", { id: `legacy-subjective-criterion-cs02-${String(item.id)}-${index + 1}`, rubricId, criterionText: text, points: 1, isMandatory: true, sortOrder: index + 1, createdAt: now, updatedAt: now });
      }
    }
    await target.query(`UPDATE kpi_template_versions SET status = 'retired', "effectiveUntil" = $1, "updatedAt" = $2 WHERE id = $3`, [effectiveUntil, now, active.id]);
  }

  await writer.upsert("audit_events", { id: MIGRATION_MARKER, occurredAt: now, actorType: "system", actorId: null, action: "migrate_pending_subjective_kpi_predicates", subjectType: "LegacyMigration", subjectId: MIGRATION_MARKER, beforeJson: { sourceMigrationApplied: false }, afterJson: { ratingSchemeId: newSchemeId, effectiveFrom }, reason: "Kesetaraan data dengan migrasi Laravel yang belum dijalankan pada sumber", createdAt: now, updatedAt: now });
}

async function verifyCopiedIds(source: mysql.Connection, target: Client) {
  const summary: Array<{ table: string; source: number; copied: number }> = [];
  for (const table of ["users", ...TABLES]) {
    const rows = await sourceRows(source, table);
    const ids = rows.map((row) => String(row.id));
    const result = ids.length ? await target.query(`SELECT COUNT(*)::int AS count FROM ${quote(table)} WHERE id = ANY($1::text[])`, [ids]) : { rows: [{ count: 0 }] };
    const copied = Number(result.rows[0].count);
    if (copied !== ids.length) throw new Error(`Verifikasi ${table} gagal: sumber ${ids.length}, ID tersalin ${copied}.`);
    summary.push({ table, source: ids.length, copied });
  }
  const userIds = (await sourceRows(source, "users")).map((row) => String(row.id));
  const accounts = await target.query(`SELECT COUNT(*)::int AS count FROM accounts WHERE "providerId" = 'credential' AND "accountId" = ANY($1::text[])`, [userIds]);
  if (Number(accounts.rows[0].count) !== userIds.length) throw new Error("Verifikasi akun credential hasil migrasi gagal.");
  return summary;
}

async function main() {
  const apply = process.argv.includes("--apply");
  const verifyOnly = process.argv.includes("--verify-only");
  if (apply && verifyOnly) throw new Error("Pilih salah satu: --apply atau --verify-only.");
  const databaseUrl = process.env.DATABASE_URL;
  if (!databaseUrl) throw new Error("DATABASE_URL PostgreSQL wajib diisi.");
  const source = await mysql.createConnection(legacyConnectionOptions());
  const target = new Client({ connectionString: databaseUrl });
  await target.connect();
  const writer = new TargetWriter(target);
  try {
    await writer.tableColumns("users");
    if (verifyOnly) {
      console.table(await verifyCopiedIds(source, target));
      console.info("Verifikasi migrasi legacy berhasil.");
      return;
    }

    const deferred: DeferredValue[] = [];
    if (apply) {
      await target.query("BEGIN");
      await target.query("SELECT pg_advisory_xact_lock($1)", [2_026_090_720_000]);
    }
    try {
      const users = await migrateUsers(source, writer, apply);
      const summary: Array<{ table: string; rows: number }> = [{ table: "users", rows: users.length }];
      for (const table of TABLES) {
        const rows = await sourceRows(source, table);
        for (const sourceRow of rows) {
          const row = await writer.mapLegacyRow(table, sourceRow, deferred);
          if (apply) await writer.upsert(table, row);
        }
        summary.push({ table, rows: rows.length });
      }
      if (apply) {
        await writer.updateDeferred(deferred);
        if (!(await sourceHasSubjectiveMigration(source))) await applyPendingSubjectiveMigration(target, writer);
        const verified = await verifyCopiedIds(source, target);
        await target.query("COMMIT");
        console.table(verified);
        console.info("Migrasi MySQL → PostgreSQL dan verifikasi ID selesai.");
      } else {
        console.table(summary);
        console.info(`Dry-run valid. ${deferred.length} relasi sirkular akan dipulihkan setelah insert. Jalankan npm run db:migrate:legacy untuk menulis data.`);
      }
    } catch (error) {
      if (apply) await target.query("ROLLBACK");
      throw error;
    }
  } finally {
    await source.end();
    await target.end();
  }
}

const invokedPath = process.argv[1] ? pathToFileURL(resolve(process.argv[1])).href : "";
if (invokedPath === import.meta.url) main().catch((error) => {
  console.error(error instanceof Error ? error.message : error);
  process.exitCode = 1;
});
