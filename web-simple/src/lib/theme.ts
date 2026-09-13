export const DEFAULT_THEME = "dark" as const;
export const THEME_STORAGE_KEY = "kpi-harian-theme";

export const THEME_META_COLORS = {
  dark: "#020806",
  light: "#fbfdfb",
} as const;

export type Theme = keyof typeof THEME_META_COLORS;

export function isTheme(value: unknown): value is Theme {
  return value === "dark" || value === "light";
}

export function parseTheme(value: unknown): Theme {
  return isTheme(value) ? value : DEFAULT_THEME;
}

export function toggleTheme(theme: Theme): Theme {
  return theme === "dark" ? "light" : "dark";
}
