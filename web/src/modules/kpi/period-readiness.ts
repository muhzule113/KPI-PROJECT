const FORMULAS = new Set(["higher_is_better", "lower_is_better", "zero_tolerance", "rubric"]);
const CADENCES = new Set(["daily", "weekly", "period"]);

type TemplateCheckItem = {
  name: string;
  weight: number;
  formula: string;
  target: number | null;
  targetJson: unknown;
  formulaParams: unknown;
  definitionActive: boolean;
  rubricCriteria: number;
};

function record(value: unknown): Record<string, unknown> {
  return value && !Array.isArray(value) && typeof value === "object" ? value as Record<string, unknown> : {};
}

export function validateTemplateConfiguration(name: string, items: TemplateCheckItem[]) {
  const issues: string[] = [];
  const total = items.reduce((sum, item) => sum + item.weight, 0);
  if (!items.length || Math.abs(total - 100) > 0.001) issues.push(`Template '${name}' harus memiliki indikator dengan total bobot tepat 100%.`);
  for (const item of items) {
    if (!item.definitionActive || item.weight <= 0) issues.push(`Indikator '${item.name}' harus aktif dan berbobot lebih dari nol.`);
    if (!FORMULAS.has(item.formula)) issues.push(`Formula indikator '${item.name}' tidak didukung.`);
    const params = record(item.formulaParams);
    const targets = record(item.targetJson);
    if (!CADENCES.has(String(params.cadence ?? "daily"))) issues.push(`Cadence indikator '${item.name}' harus daily, weekly, atau period.`);
    if (["higher_is_better", "lower_is_better"].includes(item.formula) && (!item.target || item.target <= 0)) issues.push(`Target indikator '${item.name}' wajib lebih besar dari nol.`);
    if (["lower_is_better", "zero_tolerance"].includes(item.formula)) {
      const base = item.formula === "zero_tolerance" ? Number(targets.full_score_limit ?? params.full_score_limit) : Number(item.target);
      const failure = Number(targets.failure_limit ?? params.failure_limit);
      if (!Number.isFinite(base) || !Number.isFinite(failure) || failure <= base) issues.push(`Failure limit indikator '${item.name}' belum valid.`);
    }
    if (item.formula === "rubric" && item.rubricCriteria < 1) issues.push(`Indikator rubrik '${item.name}' belum memiliki kriteria.`);
  }
  return issues;
}
