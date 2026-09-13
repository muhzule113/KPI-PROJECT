import { WrenchIcon } from "@phosphor-icons/react/dist/ssr";
import { StateVignette } from "@/components/illustrations";
import { Skeleton } from "@/components/ui/skeleton";

export default function Loading() {
  return <div className="loading-shell"><div className="loading-card" role="status" aria-live="polite">
    <span className="brand-mark" aria-hidden="true">K</span>
    <StateVignette icon={WrenchIcon} />
    <div><strong>Memuat KPI Harian</strong><p>Menyiapkan halaman dan data penilaian...</p></div>
    <div className="loading-lines" aria-hidden="true"><Skeleton /><Skeleton /><Skeleton /></div>
  </div></div>;
}
