import type { Icon } from "@phosphor-icons/react";
import type { ReactNode } from "react";
import { StateVignette } from "@/components/illustrations";

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
  return <div className="empty-state"><StateVignette icon={icon} /><div><h2>{title}</h2><p>{description}</p>{action ? <div className="empty-state-action">{action}</div> : null}</div></div>;
}
