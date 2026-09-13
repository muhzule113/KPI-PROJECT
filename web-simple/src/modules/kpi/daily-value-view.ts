import type { ValueKind, ValueStatus } from "@/generated/prisma/client";
import { categoryOptionsFromSnapshot } from "@/modules/kpi/category-options";
import { resolveCategoryBand, type CategoryBandSnapshot } from "@/modules/kpi/category-options";
import type { Direction } from "@/modules/kpi/calculation";

// Bentuk tampilan satu indikator harian. `status` membedakan belum diisi, tidak berlaku,
// dan data yang belum tersedia — nol bukan pengganti dari ketiganya.
export type DailyValueView = {
  id: string;
  name: string;
  description: string | null;
  kind: ValueKind;
  unit: string;
  target: string;
  direction: Direction;
  status: ValueStatus | null;
  value: string | null;
  predicate: string | null;
  categoryOptionId: string | null;
  choices: Array<{ id: string; label: string }>;
  bands: CategoryBandSnapshot[];
};

type SnapshotItem = {
  id: string;
  codeSnapshot: string;
  nameSnapshot: string;
  descriptionSnapshot: string | null;
  kindSnapshot: ValueKind;
  unitSnapshot: string;
  targetSnapshot: { toString(): string };
  directionSnapshot: Direction;
  categoryOptionsSnapshot: unknown;
};

type SnapshotValue = {
  monthlyKpiItemId: string;
  status: ValueStatus;
  managerStatus: ValueStatus | null;
  enteredValue: { toString(): string } | null;
  managerValue: { toString(): string } | null;
  categoryOptionId: string | null;
  managerCategoryOptionId: string | null;
};

export function dailyValueView(items: SnapshotItem[], values: SnapshotValue[]): DailyValueView[] {
  return items.map((item) => {
    const value = values.find((candidate) => candidate.monthlyKpiItemId === item.id);
    const status = value ? value.managerStatus ?? value.status : null;
    const raw = value ? value.managerValue ?? value.enteredValue : null;
    return {
      id: item.id,
      name: item.nameSnapshot,
      description: item.descriptionSnapshot,
      kind: item.kindSnapshot,
      unit: item.unitSnapshot,
      target: item.targetSnapshot.toString(),
      direction: item.directionSnapshot,
      status,
      value: status === "AVAILABLE" ? raw?.toString() ?? null : null,
      predicate: status === "AVAILABLE" ? categoryPredicate(item.categoryOptionsSnapshot, raw?.toString() ?? null, item.directionSnapshot) : null,
      categoryOptionId: status === "AVAILABLE" ? value?.managerCategoryOptionId ?? value?.categoryOptionId ?? null : null,
      choices: categoryOptionChoices(item.categoryOptionsSnapshot),
      bands: categoryBands(item.categoryOptionsSnapshot),
    };
  });
}

function categoryOptionChoices(value: unknown) {
  if (!Array.isArray(value)) return [];
  try {
    return categoryOptionsFromSnapshot(value).map((option) => ({ id: option.id, label: option.label }));
  } catch {
    return [];
  }
}

function categoryBands(value: unknown): CategoryBandSnapshot[] {
  try {
    const parsed = categoryOptionsFromSnapshot(value);
    return parsed.every((option) => "threshold" in option)
      ? parsed as CategoryBandSnapshot[]
      : [];
  } catch {
    return [];
  }
}

function categoryPredicate(snapshot: unknown, value: string | null, direction: Direction) {
  if (!value) return null;
  const bands = categoryBands(snapshot);
  return bands.length ? resolveCategoryBand(value, bands, direction)?.label ?? null : null;
}

const statusLabels = {
  PENDING: "Belum diisi",
  AVAILABLE: "Terisi",
  NOT_APPLICABLE: "Tidak berlaku",
  MISSING: "Data belum tersedia",
} as const;

// Dipakai form penilaian maupun tampilan riwayat, termasuk halaman yang tidak dapat memuat komponen klien.
export function formatDailyValue(item: Pick<DailyValueView, "status" | "value" | "unit" | "categoryOptionId" | "choices" | "predicate">) {
  if (!item.status || item.status === "PENDING") return "Belum diisi";
  if (item.status !== "AVAILABLE") return statusLabels[item.status];
  if (item.categoryOptionId) {
    const choice = item.choices.find((option) => option.id === item.categoryOptionId);
    return choice?.label ?? "Kategori tidak dikenal";
  }
  if (item.value === null) return "Belum diisi";
  return `${item.value} ${item.unit}${item.predicate ? ` · ${item.predicate}` : ""}`.trim();
}
