import { createHmac, timingSafeEqual } from "node:crypto";

const MAX_TOKEN_LIFETIME_SECONDS = 8 * 24 * 60 * 60;

function key(secret = process.env.BETTER_AUTH_SECRET) {
  if (!secret || secret.length < 32) throw new Error("BETTER_AUTH_SECRET minimal 32 karakter diperlukan untuk tautan feedback.");
  return secret;
}

export function createFeedbackToken(ticketId: string, expiresAt: Date, secret?: string) {
  const expires = Math.floor(expiresAt.valueOf() / 1000);
  const payload = `${ticketId}.${expires}`;
  const signature = createHmac("sha256", key(secret)).update(payload).digest("base64url");
  return `${payload}.${signature}`;
}

export function verifyFeedbackToken(token: string, now = new Date(), secret?: string) {
  const [ticketId, rawExpires, supplied, extra] = token.split(".");
  const expires = Number(rawExpires);
  const nowSeconds = Math.floor(now.valueOf() / 1000);
  if (extra || !ticketId || !/^[A-Za-z0-9:_-]{1,128}$/.test(ticketId) || !Number.isSafeInteger(expires) || expires < nowSeconds || expires > nowSeconds + MAX_TOKEN_LIFETIME_SECONDS || !supplied) return null;
  const expected = createHmac("sha256", key(secret)).update(`${ticketId}.${expires}`).digest();
  let actual: Buffer;
  try { actual = Buffer.from(supplied, "base64url"); } catch { return null; }
  return actual.length === expected.length && timingSafeEqual(actual, expected) ? ticketId : null;
}
