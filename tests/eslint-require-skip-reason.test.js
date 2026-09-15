/**
 * RuleTester coverage for the local `require-skip-reason` ESLint rule
 * defined in eslint-rules/require-skip-reason.cjs. Confirms the rule's
 * AST-matching logic (arity check on `test.skip(...)`/`testInfo.skip(...)`
 * calls) catches both under-specified forms and passes both legitimate
 * 2-argument overloads, so a future edit to the rule (e.g. `<=` vs `<`, or
 * a callee-matching change) can't silently break either direction. See
 * CLAUDE.md's "Playwright Test Maintenance" section and issues
 * #1949/#1950/#2070/#2068.
 *
 * Run with: node --test tests/eslint-require-skip-reason.test.js
 */

const { test } = require("node:test");
const { RuleTester } = require("eslint");
const rule = require("../eslint-rules/require-skip-reason.cjs");

test("require-skip-reason rule", () => {
    const ruleTester = new RuleTester({
        languageOptions: { ecmaVersion: 2021, sourceType: "commonjs" },
    });

    ruleTester.run("require-skip-reason", rule, {
        valid: [
            // Guard form: test.skip(condition, reason)
            "test.skip(isSlow, 'too slow in CI');",
            "test.skip(!ready, `not ready: ${reason}`);",
            // Declaration form: test.skip(title, fn) — Playwright's other
            // legitimate 2-arg overload; must not false-positive.
            "test.skip('permanently disabled', () => {});",
            // 3+ arguments: out of scope per the issue, must not be flagged.
            "test.skip(a, b, c);",
            // testInfo.skip(condition, reason) — same guard form, the
            // per-test-context idiom used in beforeEach hooks.
            "testInfo.skip(!hasRole, 'wrong project for this test');",
            // Different object named `test.skip` unrelated to Playwright's
            // test global is out of scope for this rule (arity check only
            // fires on literal `test.skip(...)`/`testInfo.skip(...)` callee
            // shape) — included to document current behavior, not to claim
            // it's exhaustive.
            "other.skip('x');",
        ],
        invalid: [
            {
                code: "test.skip();",
                errors: 1,
            },
            {
                code: "test.skip(isSlow);",
                errors: 1,
            },
            {
                code: "test.skip('reason only, no condition');",
                errors: 1,
            },
            // testInfo.skip() shares the exact same false-pass risk as
            // test.skip() and must be caught the same way (#2068 shipped a
            // bare testInfo.skip() the day this rule's original scope missed it).
            {
                code: "testInfo.skip();",
                errors: 1,
            },
            {
                code: "testInfo.skip(true);",
                errors: 1,
            },
        ],
    });
});
