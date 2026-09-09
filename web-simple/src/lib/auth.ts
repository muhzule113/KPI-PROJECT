import { betterAuth } from "better-auth";
import { prismaAdapter } from "better-auth/adapters/prisma";
import { username } from "better-auth/plugins";
import { prisma } from "@/lib/prisma";
import { isValidUsername, normalizeUsername, USERNAME_MAX_LENGTH, USERNAME_MIN_LENGTH } from "@/lib/username";

if (process.env.NODE_ENV === "production" && !process.env.APP_URL && !process.env.BETTER_AUTH_URL) {
  throw new Error("APP_URL atau BETTER_AUTH_URL wajib dikonfigurasi di production.");
}
if (process.env.NODE_ENV === "production" && (!process.env.BETTER_AUTH_SECRET || process.env.BETTER_AUTH_SECRET.length < 32)) {
  throw new Error("BETTER_AUTH_SECRET minimal 32 karakter di production.");
}

const appUrl = process.env.APP_URL ?? process.env.BETTER_AUTH_URL ?? "http://localhost:3002";
const appOrigin = new URL(appUrl).origin;
const trustedOrigins = [appOrigin];
if (process.env.NODE_ENV !== "production" && appOrigin.includes("localhost")) trustedOrigins.push(appOrigin.replace("localhost", "127.0.0.1"));

export const auth = betterAuth({
  appName: "KPI Harian",
  baseURL: appUrl,
  trustedOrigins,
  database: prismaAdapter(prisma, { provider: "postgresql" }),
  disabledPaths: ["/sign-in/email", "/request-password-reset", "/reset-password", "/is-username-available"],
  emailAndPassword: {
    enabled: true,
    disableSignUp: true,
    minPasswordLength: 10,
    maxPasswordLength: 128,
  },
  plugins: [username({
    displayUsername: false,
    minUsernameLength: USERNAME_MIN_LENGTH,
    maxUsernameLength: USERNAME_MAX_LENGTH,
    usernameNormalization: normalizeUsername,
    usernameValidator: isValidUsername,
  })],
  user: {
    additionalFields: {
      isActive: { type: "boolean", required: true, defaultValue: true, input: false },
      role: {
        type: ["ADMIN", "MANAGER", "SUPERVISOR", "EMPLOYEE"],
        required: true,
        defaultValue: "EMPLOYEE",
        input: false,
      },
    },
  },
  session: { expiresIn: 60 * 60 * 24 * 7, updateAge: 60 * 60 * 24 },
  rateLimit: {
    enabled: true,
    window: 60,
    max: 100,
    customRules: {
      "/sign-in/username": { window: 60, max: 10 },
    },
  },
  databaseHooks: {
    session: {
      create: {
        before: async (session) => {
          const user = await prisma.user.findUnique({ where: { id: session.userId }, select: { isActive: true } });
          return user?.isActive ? { data: session } : false;
        },
      },
    },
  },
  advanced: { database: { joins: true } },
});

export type AuthSession = typeof auth.$Infer.Session;
