import type { Metadata } from "next";
import { Suspense } from "react";
import { LoginForm } from "@/components/auth/login-form";

export const metadata: Metadata = { title: "Masuk" };

export default function LoginPage() {
  return <Suspense fallback={<div className="h-96 animate-pulse rounded-2xl bg-muted" />}><LoginForm /></Suspense>;
}
