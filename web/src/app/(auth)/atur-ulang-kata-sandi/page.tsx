import type { Metadata } from "next";
import { Suspense } from "react";
import { ResetPasswordForm } from "@/components/auth/reset-password-form";

export const metadata: Metadata = { title: "Atur ulang kata sandi" };
export default function ResetPasswordPage() { return <Suspense fallback={<div className="h-80 animate-pulse rounded-2xl bg-muted" />}><ResetPasswordForm /></Suspense>; }
