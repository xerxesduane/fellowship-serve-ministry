/**
 * Test runner for the assessment JavaScript.
 *
 * The PHP suite covers the plugin — intake, matching, capabilities, the whole
 * server side. It could not reach the assessment the visitor actually fills in,
 * which meant profile.js could be mutated freely and no test noticed: the object
 * that becomes a person's stored profile, and the text they download, print and
 * email, had no coverage at all.
 *
 * Usage:
 *   node tools/run-js-tests.mjs
 *   node tools/run-js-tests.mjs --filter=<substring>
 *
 * Unlike tools/run-tests.php this touches nothing. No WordPress, no database,
 * no network — the modules under test are pure functions over their arguments,
 * so there is nothing to set up and nothing to confirm before running it.
 *
 * WHAT IT DOES NOT COVER: app.js. It reads document at import time and renders
 * on load, so importing it outside a browser is not possible without a DOM
 * shim, and a shim large enough to make it import would be a bigger thing to
 * maintain than the tests it enabled. Its rendering remains checked by eye.
 */

import { readdirSync } from "node:fs";
import { fileURLToPath, pathToFileURL } from "node:url";
import { dirname, join } from "node:path";

const here = dirname(fileURLToPath(import.meta.url));
const testDir = join(here, "..", "tests", "js");

const { run } = await import(pathToFileURL(join(testDir, "bootstrap.mjs")).href);

/*
 * A snapshot of the shared content, taken before anything runs.
 *
 * shapeContent.js is imported once and handed to every caller, so a function
 * that pushes onto an array it was given mutates the questionnaire itself for
 * the rest of the session. buildProfile does exactly that kind of push, and the
 * only reason it is safe is that the array it appends to was freshly built.
 * This is the JS analogue of the PHP runner's fixture-leak check: it is the
 * failure that will not show up as a failing assertion.
 */
const contentUrl = pathToFileURL(
  join(here, "..", "wordpress-plugin", "serve-dashboard", "public", "assessment", "shapeContent.js")
).href;
const content = await import(contentUrl);
const before = JSON.stringify(content);

const files = readdirSync(testDir)
  .filter((f) => f.startsWith("test-") && f.endsWith(".mjs"))
  .sort();

for (const file of files) {
  await import(pathToFileURL(join(testDir, file)).href);
}

const filter = (process.argv.find((a) => a.startsWith("--filter=")) || "").slice("--filter=".length);

let status = await run(filter);

if (JSON.stringify(content) !== before) {
  process.stdout.write(
    "  WARNING: shapeContent changed during the run. Something mutated the shared questionnaire.\n"
  );
  status = 1;
}

process.stdout.write("\n");
process.exit(status);
