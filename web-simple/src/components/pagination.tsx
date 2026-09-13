import Link from "next/link";
import { Button } from "@/components/ui/button";

export function Pagination({ page, totalPages, hrefForPage }: { page: number; totalPages: number; hrefForPage: (page: number) => string }) {
  if (totalPages <= 1) return null;
  return <nav className="pagination" aria-label="Navigasi halaman">
    <span className="pagination-summary">Halaman {page} dari {totalPages}</span>
    <div className="pagination-actions">
      {page > 1 ? <Button asChild variant="secondary" size="small"><Link href={hrefForPage(page - 1)}>Sebelumnya</Link></Button> : <span className="pagination-control is-disabled" aria-disabled="true">Sebelumnya</span>}
      {page < totalPages ? <Button asChild variant="secondary" size="small"><Link href={hrefForPage(page + 1)}>Berikutnya</Link></Button> : <span className="pagination-control is-disabled" aria-disabled="true">Berikutnya</span>}
    </div>
  </nav>;
}
