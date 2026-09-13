"use client";

import { useServerInsertedHTML } from "next/navigation";
import { THEME_META_COLORS, THEME_STORAGE_KEY } from "@/lib/theme";

const themeBootstrap = `try {
  if (localStorage.getItem("${THEME_STORAGE_KEY}") === "light") {
    document.documentElement.dataset.theme = "light";
    document.querySelector('meta[name="theme-color"]')?.setAttribute("content", "${THEME_META_COLORS.light}");
  }
} catch {}`;

export function ThemeBootstrap() {
  useServerInsertedHTML(() => <script id="theme-bootstrap" dangerouslySetInnerHTML={{ __html: themeBootstrap }} />);
  return null;
}
