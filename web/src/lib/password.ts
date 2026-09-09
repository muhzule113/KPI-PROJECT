import bcrypt from "bcryptjs";
import { hashPassword, verifyPassword } from "better-auth/crypto";

export const hashApplicationPassword = hashPassword;

export async function verifyApplicationPassword({ hash, password }: { hash: string; password: string }) {
  if (/^\$2[aby]\$/.test(hash)) {
    return bcrypt.compare(password, hash.replace(/^\$2y\$/, "$2b$"));
  }
  return verifyPassword({ hash, password });
}
