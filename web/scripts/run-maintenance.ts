import { prisma } from "../src/lib/prisma";
import { runScheduledMaintenance } from "../src/modules/jobs/maintenance";

try {
  process.stdout.write(`${JSON.stringify(await runScheduledMaintenance())}\n`);
} catch (error) {
  process.stderr.write(`${error instanceof Error ? error.stack ?? error.message : String(error)}\n`);
  process.exitCode = 1;
} finally {
  await prisma.$disconnect();
}
