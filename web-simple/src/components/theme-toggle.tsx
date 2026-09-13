"use client";

import { MoonIcon, SunIcon } from "@phosphor-icons/react";
import { useEffect, useSyncExternalStore } from "react";
import { cn } from "@/lib/utils";
import {
  DEFAULT_THEME,
  THEME_META_COLORS,
  THEME_STORAGE_KEY,
  parseTheme,
  toggleTheme,
  type Theme,
} from "@/lib/theme";

const THEME_EVENT = "kpi-harian-theme-change";
let activeTheme: Theme | null = null;

function readTheme(): Theme {
  if (activeTheme) return activeTheme;
  const documentTheme = typeof document !== "undefined" ? document.documentElement.dataset.theme : undefined;

  try {
    const storedTheme = window.localStorage.getItem(THEME_STORAGE_KEY);
    activeTheme = storedTheme === null ? parseTheme(documentTheme) : parseTheme(storedTheme);
  } catch {
    activeTheme = parseTheme(documentTheme);
  }

  return activeTheme;
}

function applyTheme(theme: Theme, persist: boolean) {
  activeTheme = theme;
  document.documentElement.dataset.theme = theme;
  document.querySelector<HTMLMetaElement>('meta[name="theme-color"]')?.setAttribute("content", THEME_META_COLORS[theme]);

  if (!persist) return;
  try {
    window.localStorage.setItem(THEME_STORAGE_KEY, theme);
  } catch {
    // Theme remains active for the current page when browser storage is unavailable.
  }
}

function subscribe(onStoreChange: () => void) {
  const handleStorage = () => {
    activeTheme = null;
    onStoreChange();
  };
  window.addEventListener(THEME_EVENT, onStoreChange);
  window.addEventListener("storage", handleStorage);
  return () => {
    window.removeEventListener(THEME_EVENT, onStoreChange);
    window.removeEventListener("storage", handleStorage);
  };
}

export function ThemeToggle({ className }: { className?: string }) {
  const theme = useSyncExternalStore(subscribe, readTheme, () => DEFAULT_THEME);
  const nextLabel = theme === "light" ? "Gunakan mode gelap" : "Gunakan mode terang";
  const Icon = theme === "light" ? MoonIcon : SunIcon;

  useEffect(() => {
    applyTheme(readTheme(), false);
  }, []);

  function handleToggle() {
    const nextTheme = toggleTheme(theme);
    applyTheme(nextTheme, true);
    window.dispatchEvent(new Event(THEME_EVENT));
  }

  return <button
    className={cn("theme-toggle", className)}
    type="button"
    onClick={handleToggle}
    aria-label={nextLabel}
    aria-pressed={theme === "light"}
    title={nextLabel}
  >
    <span className="theme-toggle-icon" aria-hidden="true"><Icon size={19} weight="bold" /></span>
    <span>{theme === "light" ? "Mode gelap" : "Mode terang"}</span>
  </button>;
}
