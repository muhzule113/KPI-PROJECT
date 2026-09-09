import { PrismaPg } from "@prisma/adapter-pg";
import { PrismaClient } from "@/generated/prisma/client";

const connectionString = process.env.DATABASE_URL;
if (!connectionString) throw new Error("DATABASE_URL belum dikonfigurasi.");

const adapter = new PrismaPg({ connectionString });
const globalForPrisma = globalThis as unknown as { kpiSimplePrisma?: PrismaClient };

export const prisma = globalForPrisma.kpiSimplePrisma ?? new PrismaClient({ adapter });

if (process.env.NODE_ENV !== "production") globalForPrisma.kpiSimplePrisma = prisma;
