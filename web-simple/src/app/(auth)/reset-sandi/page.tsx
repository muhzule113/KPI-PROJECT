import { Suspense } from "react";
import { ResetPasswordForm } from "@/components/auth-form";

export default function ResetPage() { return <Suspense fallback={<p>Memuat formulir...</p>}><ResetPasswordForm /></Suspense>; }
