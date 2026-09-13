import type { Metadata, Viewport } from "next";
import localFont from "next/font/local";
import type { ReactNode } from "react";
import { ThemeBootstrap } from "@/components/theme-bootstrap";
import { ToastProvider } from "@/components/toast-provider";
import { THEME_META_COLORS } from "@/lib/theme";
import "@daypicker/react/style.css";
import "./globals.css";

const instrumentSans = localFont({
  src: [
    { path: "./fonts/instrument-sans/instrument-sans-400.woff2", weight: "400", style: "normal" },
    { path: "./fonts/instrument-sans/instrument-sans-500.woff2", weight: "500", style: "normal" },
    { path: "./fonts/instrument-sans/instrument-sans-600.woff2", weight: "600", style: "normal" },
  ],
  variable: "--font-sans",
  display: "swap",
  fallback: ["-apple-system", "BlinkMacSystemFont", "Segoe UI", "Roboto", "Helvetica Neue", "Arial", "sans-serif"],
});

export const metadata: Metadata = {
  title: { default: "KPI Harian", template: "%s · KPI Harian" },
  description: "Penilaian KPI harian dan rekap bulanan toko.",
};

export const viewport: Viewport = { width: "device-width", initialScale: 1, themeColor: THEME_META_COLORS.dark };

export default function RootLayout({ children }: Readonly<{ children: ReactNode }>) {
  return <html lang="id" data-theme="dark" suppressHydrationWarning>
    <head><ThemeBootstrap /></head>
    <body className={instrumentSans.variable}><ToastProvider>{children}</ToastProvider></body>
  </html>;
}
