import type { Metadata, Viewport } from "next";
import localFont from "next/font/local";
import type { ReactNode } from "react";
import { ToastProvider } from "@/components/toast-provider";
import "@daypicker/react/style.css";
import "./globals.css";

const bricolageGrotesque = localFont({
  src: "./fonts/BricolageGrotesque-Variable.ttf",
  variable: "--font-bricolage",
  weight: "200 800",
  display: "swap",
});

export const metadata: Metadata = {
  title: { default: "KPI Harian", template: "%s · KPI Harian" },
  description: "Penilaian KPI harian dan rekap bulanan toko.",
};

export const viewport: Viewport = { width: "device-width", initialScale: 1, themeColor: "#020806" };

export default function RootLayout({ children }: Readonly<{ children: ReactNode }>) {
  return <html lang="id"><body className={bricolageGrotesque.variable}><ToastProvider>{children}</ToastProvider></body></html>;
}
