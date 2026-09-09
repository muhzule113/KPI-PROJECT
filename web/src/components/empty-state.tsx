import type { Icon } from "@phosphor-icons/react";

export function EmptyState({
  icon: IconComponent,
  title,
  description,
  action,
}: {
  icon: Icon;
  title: string;
  description: string;
  action?: React.ReactNode;
}) {
  return (
    <div className="flex min-h-56 flex-col items-center justify-center rounded-2xl border border-dashed bg-card px-6 py-10 text-center">
      <span className="mb-4 grid size-11 place-items-center rounded-xl bg-accent text-primary">
        <IconComponent aria-hidden="true" size={22} weight="duotone" />
      </span>
      <h2 className="text-base font-semibold">{title}</h2>
      <p className="mt-1 max-w-sm text-sm leading-6 text-muted-foreground">{description}</p>
      {action ? <div className="mt-5">{action}</div> : null}
    </div>
  );
}
