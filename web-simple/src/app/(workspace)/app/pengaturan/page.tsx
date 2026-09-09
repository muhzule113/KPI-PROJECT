import { redirect } from "next/navigation";

const destinations: Record<string, string> = {
  organisasi: "jabatan",
  akun: "pengguna",
  indikator: "indikator",
  periode: "periode",
};

export default async function SettingsRedirect({ searchParams }: {
  searchParams: Promise<{ tab?: string; accountId?: string; positionId?: string }>;
}) {
  const query = await searchParams;
  const section = destinations[query.tab ?? ""] ?? "jabatan";
  const forwarded = new URLSearchParams();
  if (query.accountId && section === "pengguna") forwarded.set("accountId", query.accountId);
  if (query.positionId && section === "indikator") forwarded.set("positionId", query.positionId);
  redirect(`/app/pengaturan/${section}${forwarded.size ? `?${forwarded}` : ""}`);
}
