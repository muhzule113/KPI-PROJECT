"use client";

import { useRouter } from "next/navigation";
import { useEffect } from "react";

export function ImportStatusRefresher() {
  const router = useRouter();
  useEffect(() => {
    const timer = window.setInterval(() => router.refresh(), 3_000);
    return () => window.clearInterval(timer);
  }, [router]);
  return <p role="status" className="rounded-xl border border-primary/25 bg-primary/5 px-4 py-3 text-sm text-primary">File sedang diproses di latar belakang. Status diperbarui otomatis.</p>;
}
