import test from "node:test";
import assert from "node:assert/strict";
import { DEFAULT_THEME, parseTheme, toggleTheme } from "../src/lib/theme.ts";

test("tema hanya menerima dark atau light", () => {
  assert.equal(parseTheme("dark"), "dark");
  assert.equal(parseTheme("light"), "light");
  assert.equal(parseTheme("sepia"), DEFAULT_THEME);
  assert.equal(parseTheme(null), DEFAULT_THEME);
});

test("toggle tema berpindah dua arah", () => {
  assert.equal(toggleTheme("dark"), "light");
  assert.equal(toggleTheme("light"), "dark");
});
