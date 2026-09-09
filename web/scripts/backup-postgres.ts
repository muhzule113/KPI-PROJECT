import { mkdir } from "node:fs/promises";
import { join, resolve } from "node:path";
import { databaseConfig, runPostgresTool } from "./postgres-tools";

const config = databaseConfig();
const directory = resolve(process.env.BACKUP_DIR?.trim() || join(process.cwd(), "backups"));
await mkdir(directory, { recursive: true });
const timestamp = new Date().toISOString().replace(/[:.]/g, "-");
const output = join(directory, `${config.database}-${timestamp}.dump`);
await runPostgresTool("pg_dump", ["--format=custom", "--no-owner", "--no-privileges", "--file", output], config.database);
process.stdout.write(`${output}\n`);
