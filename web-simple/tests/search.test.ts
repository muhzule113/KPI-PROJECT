import assert from "node:assert/strict";
import test from "node:test";
import { matchesSearch, parseSearch, SEARCH_MAX_LENGTH } from "../src/lib/search.ts";

test("pencarian menormalisasi spasi dan membatasi panjang query", () => {
  assert.equal(parseSearch("  manager  "), "manager");
  assert.equal(parseSearch("   "), "");
  assert.equal(parseSearch(["  supervisor  ", "ignored"]), "supervisor");
  assert.equal(parseSearch("x".repeat(SEARCH_MAX_LENGTH + 20)).length, SEARCH_MAX_LENGTH);
});

test("pencarian cocok sebagian tanpa membedakan kapitalisasi", () => {
  assert.equal(matchesSearch("  mana  ", "Manager Cabang", "Supervisor"), true);
  assert.equal(matchesSearch("kode-2", "KODE-20"), true);
  assert.equal(matchesSearch("teknisi", "Pegawai", "Kasir"), false);
  assert.equal(matchesSearch("", "apa saja"), true);
});
