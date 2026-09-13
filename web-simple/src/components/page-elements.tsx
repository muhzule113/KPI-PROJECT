import type { Icon } from "@phosphor-icons/react";
import type { ReactNode } from "react";
import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from "@/components/ui/empty";

export function PageHeader({ eyebrow, title, description, actions }: { eyebrow?: string; title: string; description?: string; actions?: ReactNode }) {
  return (
    <header className="page-header">
      <div>
        {eyebrow ? <p className="eyebrow">{eyebrow}</p> : null}
        <h1>{title}</h1>
        {description ? <p className="page-description">{description}</p> : null}
      </div>
      {actions ? <div className="page-actions">{actions}</div> : null}
    </header>
  );
}

export function EmptyState({ title, description, icon, action }: { title: string; description: string; icon: Icon; action?: ReactNode }) {
  const Icon = icon;
  return <Empty className="empty-state">
    <EmptyMedia><Icon size={24} weight="duotone" aria-hidden="true" /></EmptyMedia>
    <EmptyHeader><EmptyTitle>{title}</EmptyTitle><EmptyDescription>{description}</EmptyDescription></EmptyHeader>
    {action ? <EmptyContent className="empty-state-action">{action}</EmptyContent> : null}
  </Empty>;
}
