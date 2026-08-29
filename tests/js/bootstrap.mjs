/**
 * Test harness for the assessment JavaScript.
 *
 * Deliberately the same shape as tests/bootstrap.php — test(), an Assert with
 * the same five methods, the same tick-and-cross output, and the same refusal
 * to pass a test that asserted nothing. Somebody who has read one suite can
 * read the other, and the runner tells them the same things in the same words.
 *
 * No dependencies and no package.json, for the same reason the PHP suite has no
 * Composer: the plugin has no build step, and adding a package manager to run a
 * handful of tests would be a bigger change to this repository than anything it
 * tests. Node can import the assessment's ES modules directly, unchanged, from
 * the same files the browser loads.
 */

/** @type {{name: string, fn: Function}[]} */
const tests = [];

export function test(name, fn) {
  tests.push({ name, fn });
}

class Failure extends Error {}

/**
 * Assertions.
 *
 * Each says what it expected and what it got, because a failing test that only
 * says "false is not true" costs more time than it saves.
 */
export class Assert {
  count = 0;

  ok(value, what) {
    this.count += 1;
    if (!value) throw new Failure(`expected true: ${what}`);
  }

  not(value, what) {
    this.count += 1;
    if (value) throw new Failure(`expected false: ${what}`);
  }

  /*
   * PHP's === compares arrays by value, so `same` there reads naturally against
   * a whole structure. Doing that here means a deep comparison rather than
   * reference identity — otherwise every assertion about a profile would have
   * to be spelled out field by field, and the tests would check less.
   */
  same(expected, actual, what) {
    this.count += 1;
    if (!deepEqual(expected, actual)) {
      throw new Failure(
        `${what}\n      expected: ${show(expected)}\n      actual:   ${show(actual)}`
      );
    }
  }

  contains(needle, haystack, what) {
    this.count += 1;
    if (!String(haystack).includes(needle)) {
      throw new Failure(`${what}\n      "${needle}" not found in: ${show(haystack)}`);
    }
  }

  lacks(needle, haystack, what) {
    this.count += 1;
    if (String(haystack).includes(needle)) {
      throw new Failure(`${what}\n      "${needle}" WAS found in: ${show(haystack)}`);
    }
  }
}

function deepEqual(a, b) {
  if (a === b) return true;
  if (Number.isNaN(a) && Number.isNaN(b)) return true;
  if (a === null || b === null || typeof a !== "object" || typeof b !== "object") return false;
  if (Array.isArray(a) !== Array.isArray(b)) return false;
  const ka = Object.keys(a);
  const kb = Object.keys(b);
  if (ka.length !== kb.length) return false;
  // Key order is not part of the value, so compare by name rather than position.
  return ka.every((k) => Object.prototype.hasOwnProperty.call(b, k) && deepEqual(a[k], b[k]));
}

function show(value) {
  const out = typeof value === "string" ? JSON.stringify(value) : JSON.stringify(value) ?? String(value);
  return out.length > 300 ? `${out.slice(0, 300)}...` : out;
}

export async function run(filter = "") {
  let selected = tests;
  if (filter !== "") {
    const needle = filter.toLowerCase();
    selected = tests.filter((t) => t.name.toLowerCase().includes(needle));
  }

  let passed = 0;
  let failed = 0;
  let assertions = 0;

  process.stdout.write("\n");

  for (const t of selected) {
    const assert = new Assert();
    try {
      await t.fn(assert);

      /*
       * A test that asserted nothing passes for free and reads in the output
       * exactly like one that checked something. That is worse than no test,
       * because it occupies the space where a real one would go.
       */
      if (assert.count === 0) throw new Failure("this test made no assertions");

      process.stdout.write(`  \u2713 ${t.name}\n`);
      passed += 1;
    } catch (e) {
      if (e instanceof Failure) {
        process.stdout.write(`  \u2717 ${t.name}\n      ${e.message}\n`);
      } else {
        process.stdout.write(`  \u2717 ${t.name}\n      ${e.name}: ${e.message}\n      ${firstFrame(e)}\n`);
      }
      failed += 1;
    } finally {
      assertions += assert.count;
    }
  }

  process.stdout.write(`\n  ${passed} passed, ${failed} failed, ${assertions} assertions\n`);

  return failed > 0 ? 1 : 0;
}

function firstFrame(e) {
  const line = String(e.stack || "").split("\n").find((l) => l.includes("/tests/js/") || l.includes("\tests\js\\"));
  return (line || "").trim();
}
