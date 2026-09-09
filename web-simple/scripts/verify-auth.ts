import "dotenv/config";

import assert from "node:assert/strict";
import { randomUUID } from "node:crypto";
import { PrismaPg } from "@prisma/adapter-pg";
import { hashPassword } from "better-auth/crypto";
import { PrismaClient } from "../src/generated/prisma/client.ts";

const connectionString = process.env.DATABASE_URL;
if (!connectionString) throw new Error("DATABASE_URL wajib diisi.");

const baseUrl = process.env.APP_URL ?? process.env.BETTER_AUTH_URL ?? "http://localhost:3002";
const origin = new URL(baseUrl).origin;
const prisma = new PrismaClient({ adapter: new PrismaPg({ connectionString }) });
const marker = randomUUID().replaceAll("-", "").slice(0, 10);
const active = { id: randomUUID(), username: `smoke.user.${marker}` };
const inactive = { id: randomUUID(), username: `smoke.inactive.${marker}` };
const password = "smoke-password-2026";

async function post(path: string, body: Record<string, unknown>, cookie?: string) {
  return fetch(new URL(path, baseUrl), {
    method: "POST",
    headers: { "content-type": "application/json", origin, ...(cookie ? { cookie } : {}) },
    body: JSON.stringify(body),
    redirect: "manual",
  });
}

async function errorSignature(response: Response) {
  const body = await response.json() as { code?: string; message?: string };
  return { status: response.status, code: body.code, message: body.message };
}

try {
  const passwordHash = await hashPassword(password);
  await prisma.$transaction(async (tx) => {
    for (const user of [{ ...active, isActive: true }, { ...inactive, isActive: false }]) {
      await tx.user.create({ data: { id: user.id, username: user.username, email: `${user.id}@users.kpi.invalid`, emailVerified: true, name: "Auth Smoke", role: "EMPLOYEE", isActive: user.isActive } });
      await tx.account.create({ data: { userId: user.id, providerId: "credential", accountId: user.id, password: passwordHash } });
    }
  });

  const login = await post("/api/auth/sign-in/username", { username: active.username.toUpperCase(), password });
  assert.equal(login.status, 200, "Login username case-insensitive harus berhasil.");
  const setCookies = login.headers.getSetCookie();
  assert.ok(setCookies.length, "Login harus membuat cookie sesi.");
  const cookie = setCookies.map((value) => value.split(";", 1)[0]).join("; ");
  assert.equal((await post("/api/auth/sign-out", {}, cookie)).status, 200, "Sesi smoke test harus dapat ditutup.");

  const wrongPassword = await post("/api/auth/sign-in/username", { username: active.username, password: "wrong-password-2026" });
  const unknownUsername = await post("/api/auth/sign-in/username", { username: `unknown.${marker}`, password: "wrong-password-2026" });
  assert.deepEqual(await errorSignature(wrongPassword), await errorSignature(unknownUsername), "Respons kredensial salah tidak boleh membocorkan keberadaan username.");

  const inactiveLogin = await post("/api/auth/sign-in/username", { username: inactive.username, password });
  assert.notEqual(inactiveLogin.status, 200, "Akun nonaktif tidak boleh membuat sesi.");
  assert.equal(inactiveLogin.headers.getSetCookie().length, 0, "Akun nonaktif tidak boleh menerima cookie sesi.");

  assert.equal((await post("/api/auth/sign-in/email", { email: "nobody@example.com", password })).status, 404);
  assert.equal((await post("/api/auth/request-password-reset", { email: "nobody@example.com" })).status, 404);
  assert.equal((await post("/api/auth/reset-password", { token: "invalid", newPassword: password })).status, 404);
  assert.equal((await post("/api/auth/is-username-available", { username: active.username })).status, 404);
  assert.equal((await fetch(new URL("/lupa-sandi", baseUrl))).status, 404);
  assert.equal((await fetch(new URL("/reset-sandi", baseUrl))).status, 404);
} finally {
  await prisma.user.deleteMany({ where: { id: { in: [active.id, inactive.id] } } });
  await prisma.$disconnect();
}

console.info("Verifikasi auth lulus: login username, anti-enumerasi, akun nonaktif, dan endpoint email/reset.");
