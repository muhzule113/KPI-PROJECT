import "dotenv/config";
import { defineConfig } from "prisma/config";

export default defineConfig({
  schema: "prisma/schema.prisma",
  migrations: {
    path: "prisma/migrations",
    seed: "node --import ./scripts/node-userinfo-workaround.mjs --import tsx prisma/seed.ts",
  },
  datasource: {
    url: process.env.DATABASE_URL ?? "postgresql://kpi_simple:local-development@127.0.0.1:5435/kpi_simple?schema=public",
  },
});
