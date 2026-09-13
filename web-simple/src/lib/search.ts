export const SEARCH_MAX_LENGTH = 100;

// ponytail: contains scans fit current list sizes; add pg_trgm when search latency warrants it.

export function parseSearch(value?: string | string[]) {
  const raw = Array.isArray(value) ? value[0] : value;
  return typeof raw === "string" ? raw.trim().slice(0, SEARCH_MAX_LENGTH) : "";
}

export function matchesSearch(search: string, ...values: Array<string | null | undefined>) {
  const normalized = parseSearch(search).toLocaleLowerCase("id-ID");
  if (!normalized) return true;
  return values.some((value) => value?.toLocaleLowerCase("id-ID").includes(normalized));
}
