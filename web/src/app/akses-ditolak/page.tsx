import { WarningCircleIcon } from "@phosphor-icons/react/dist/ssr";
import Link from "next/link";
import { Button } from "@/components/ui/button";

export default async function AccessDeniedPage({ searchParams }: { searchParams: Promise<{ reason?: string }> }) {
  const { reason } = await searchParams;
  return (
    <main className="grid min-h-dvh place-items-center bg-background px-4">
      <div className="max-w-md text-center">
        <WarningCircleIcon className="mx-auto text-amber-600" size={52} weight="duotone" />
        <h1 className="mt-5 text-2xl font-semibold">Akses belum tersedia</h1>
        <p className="mt-2 text-sm leading-6 text-muted-foreground">{reason ?? "Akun ini belum memiliki akses ke halaman yang diminta."}</p>
        <Button asChild className="mt-6"><Link href="/login">Kembali ke login</Link></Button>
      </div>
    </main>
  );
}
