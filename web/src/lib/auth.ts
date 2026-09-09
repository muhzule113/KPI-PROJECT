import { betterAuth } from "better-auth";
import { prismaAdapter } from "better-auth/adapters/prisma";
import { prisma } from "@/lib/prisma";
import { sendPasswordResetEmail } from "@/lib/email";
import { hashApplicationPassword, verifyApplicationPassword } from "@/lib/password";

export const auth = betterAuth({
  appName: "KPI OPS",
  database: prismaAdapter(prisma, { provider: "postgresql" }),
  emailAndPassword: {
    enabled: true,
    disableSignUp: process.env.ALLOW_SIGN_UP !== "true",
    minPasswordLength: 10,
    maxPasswordLength: 128,
    resetPasswordTokenExpiresIn: 60 * 60,
    revokeSessionsOnPasswordReset: true,
    password: {
      hash: hashApplicationPassword,
      verify: verifyApplicationPassword,
    },
    sendResetPassword: async ({ user, url }) => {
      void sendPasswordResetEmail({ to: user.email, name: user.name, url }).catch(
        (error) => console.error("Gagal mengirim email reset kata sandi", error),
      );
    },
  },
  user: {
    additionalFields: {
      isActive: {
        type: "boolean",
        required: true,
        defaultValue: true,
        input: false,
      },
      role: {
        type: [
          "super_admin",
          "kpi_admin",
          "auditor",
          "owner_manager",
          "supervisor",
          "employee",
        ],
        required: true,
        defaultValue: "employee",
        input: false,
      },
    },
  },
  session: {
    expiresIn: 60 * 60 * 24 * 7,
    updateAge: 60 * 60 * 24,
  },
  databaseHooks: {
    session: {
      create: {
        before: async (session) => {
          const user = await prisma.user.findUnique({
            where: { id: session.userId },
            select: { isActive: true },
          });
          return user?.isActive ? { data: session } : false;
        },
      },
    },
  },
  advanced: {
    database: { joins: true },
  },
});

export type AuthSession = typeof auth.$Infer.Session;
