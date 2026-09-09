import type { Prisma } from "@/generated/prisma/client";

export function presentAuditJson(value: Prisma.JsonValue | null) {
  if (value == null) return null;
  return JSON.stringify(value, (key, item) => /password|token|secret/i.test(key) ? "[disembunyikan]" : item, 2);
}
