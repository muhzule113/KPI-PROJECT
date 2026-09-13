import type { Metadata, Viewport } from "next";
import localFont from "next/font/local";
import type { ReactNode } from "react";
import { ThemeBootstrap } from "@/components/theme-bootstrap";
import { ToastProvider } from "@/components/toast-provider";
import { THEME_META_COLORS } from "@/lib/theme";
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

export const viewport: Viewport = { width: "device-width", initialScale: 1, themeColor: THEME_META_COLORS.dark };

export default function RootLayout({ children }: Readonly<{ children: ReactNode }>) {
  return <html lang="id" data-theme="dark" suppressHydrationWarning>
    <head><ThemeBootstrap /></head>
    <body className={bricolageGrotesque.variable}><ToastProvider>{children}</ToastProvider></body>
  </html>;
}
