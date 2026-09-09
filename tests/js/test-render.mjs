/**
 * escapeHtml and icon — used by every view in the journey.
 */

import { readFileSync, readdirSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

import { test } from "./bootstrap.mjs";
import { ICON_PATHS, escapeHtml, icon, iconNames } from "../../serve-standalone/public/assessment/render.js";

const ASSESSMENT = join(
  dirname(fileURLToPath(import.meta.url)),
  "..", "..", "serve-standalone", "public", "assessment"
);

test("every character that could break out of markup is escaped", (a) => {
  a.same("&lt;script&gt;", escapeHtml("<script>"), "angle brackets");
  a.same("&amp;amp;", escapeHtml("&amp;"), "ampersands, including ones already escaped");
  a.same("&quot;a&#39;b&quot;", escapeHtml('"a\'b"'), "both kinds of quote, so an attribute cannot be closed early");
  a.same("", escapeHtml(), "no argument is an empty string, not the word undefined");
  a.same("0", escapeHtml(0), "and a falsy number survives");
});

test("an icon is drawn at the size it was asked for", (a) => {
  const svg = icon("check", 17);

  a.contains('width="17" height="17"', svg, "the requested size");
  a.contains(ICON_PATHS.check, svg, "and the right path");
  a.contains('aria-hidden="true"', svg, "hidden from screen readers, since every icon here sits beside its own label");
});

test("every icon the journey asks for is one that exists", (a) => {
  /*
   * An unknown name renders an empty <svg>: correctly sized, invisible, and
   * silent. Every icon on the leader dashboard was once drawn at 0x0 for a
   * neighbouring reason, and it took a screenshot to notice.
   */
  const defined = new Set(iconNames());
  const asked = new Set();

  for (const file of readdirSync(ASSESSMENT).filter((f) => f.endsWith(".js"))) {
    const source = readFileSync(join(ASSESSMENT, file), "utf8");
    for (const match of source.matchAll(/\bicon\(\s*"([a-zA-Z]+)"/g)) {
      asked.add(match[1]);
    }
  }

  a.ok(asked.size > 10, "the scan found the icon calls at all");
  a.same([], [...asked].filter((name) => !defined.has(name)), "no icon is asked for by a name nothing defines");
});

test("an unknown name yields an empty icon rather than throwing", (a) => {
  // The page must still render. Failing loudly here would take the whole
  // journey down over a decoration.
  const svg = icon("no-such-icon");

  a.contains("<svg", svg, "still an element");
  a.lacks("<path", svg, "with nothing drawn in it");
});
