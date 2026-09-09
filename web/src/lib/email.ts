import nodemailer from "nodemailer";

type ResetEmail = {
  to: string;
  name: string;
  url: string;
};

export async function sendPasswordResetEmail({ to, name, url }: ResetEmail) {
  if (!process.env.SMTP_HOST) {
    if (process.env.NODE_ENV === "development") {
      console.info(`[dev-only] Tautan reset untuk ${to}: ${url}`);
      return;
    }
    throw new Error("SMTP belum dikonfigurasi.");
  }

  const transporter = nodemailer.createTransport({
    host: process.env.SMTP_HOST,
    port: Number(process.env.SMTP_PORT ?? 587),
    secure: process.env.SMTP_SECURE === "true",
    auth: process.env.SMTP_USER
      ? { user: process.env.SMTP_USER, pass: process.env.SMTP_PASSWORD }
      : undefined,
  });

  await transporter.sendMail({
    from: process.env.SMTP_FROM ?? "KPI OPS <no-reply@example.com>",
    to,
    subject: "Pulihkan akses KPI OPS",
    text: `Halo ${name}, buka tautan berikut untuk membuat kata sandi baru: ${url}`,
    html: `<p>Halo ${escapeHtml(name)},</p><p>Buka tautan berikut untuk membuat kata sandi baru:</p><p><a href="${escapeHtml(url)}">Buat kata sandi baru</a></p><p>Tautan berlaku selama satu jam.</p>`,
  });
}

function escapeHtml(value: string) {
  return value.replace(
    /[&<>"']/g,
    (character) =>
      ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#039;" })[
        character
      ]!,
  );
}
