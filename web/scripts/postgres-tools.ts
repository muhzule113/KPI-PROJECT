import "dotenv/config";
import { execFile } from "node:child_process";
import { access } from "node:fs/promises";
import { join } from "node:path";
import { promisify } from "node:util";

export function databaseConfig(raw = process.env.DATABASE_URL) {
  if (!raw) throw new Error("DATABASE_URL wajib diisi.");
  const url = new URL(raw);
  if (url.protocol !== "postgresql:" && url.protocol !== "postgres:") throw new Error("DATABASE_URL wajib memakai PostgreSQL.");
  const database = decodeURIComponent(url.pathname.replace(/^\//, ""));
  if (!database) throw new Error("Nama database tidak ditemukan pada DATABASE_URL.");
  return { host: url.hostname, port: url.port || "5432", user: decodeURIComponent(url.username), password: decodeURIComponent(url.password), database, sslmode: url.searchParams.get("sslmode") };
}

export async function postgresTool(name: "pg_dump" | "pg_restore" | "createdb" | "dropdb" | "psql") {
  const extension = process.platform === "win32" ? ".exe" : "";
  const configured = process.env.PG_BIN?.trim();
  const candidates = configured ? [join(configured, `${name}${extension}`)] : process.platform === "win32" ? [18, 17, 16, 15].map((version) => join("C:\\Program Files\\PostgreSQL", String(version), "bin", `${name}.exe`)) : [name];
  for (const candidate of candidates) {
    if (candidate === name) return candidate;
    try { await access(candidate); return candidate; } catch { /* lanjut ke versi berikutnya */ }
  }
  throw new Error(`${name} tidak ditemukan. Isi PG_BIN dengan folder bin PostgreSQL.`);
}

export async function runPostgresTool(name: Parameters<typeof postgresTool>[0], args: string[], databaseName?: string) {
  const config = databaseConfig();
  const command = await postgresTool(name);
  const connection = ["--host", config.host, "--port", config.port, "--username", config.user];
  if (databaseName) connection.push("--dbname", databaseName);
  return promisify(execFile)(command, [...connection, ...args], { windowsHide: true, timeout: 300_000, maxBuffer: 8 * 1024 * 1024, env: { ...process.env, PGPASSWORD: config.password, ...(config.sslmode ? { PGSSLMODE: config.sslmode } : {}) } });
}

export async function databaseCounts(databaseName: string) {
  const tables = ["users", "employees", "employee_kpis", "service_tickets", "import_batches", "audit_events"];
  const expression = tables.map((table) => `'${table}', (SELECT COUNT(*) FROM ${table})`).join(", ");
  const { stdout } = await runPostgresTool("psql", ["--tuples-only", "--no-align", "--set", "ON_ERROR_STOP=1", "--command", `SELECT json_build_object(${expression})::text;`], databaseName);
  return JSON.parse(stdout.trim()) as Record<string, number>;
}
