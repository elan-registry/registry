/**
 * RuleTester coverage for the local `require-skip-reason` ESLint rule
 * defined in eslint.config.mjs. Confirms the rule's AST-matching logic
 * (arity check on `test.skip(...)` calls) catches both under-specified
 * forms and passes both legitimate 2-argument overloads, so a future edit
 * to the rule (e.g. `<=` vs `<`, or a callee-matching change) can't
 * silently break either direction. See CLAUDE.md's "Playwright Test
 * Maintenance" section and issues #1949/#1950/#2070.
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
            // Different object named `test.skip` unrelated to Playwright's
            // test global is out of scope for this rule (arity check only
            // fires on literal `test.skip(...)` callee shape) — included to
            // document current behavior, not to claim it's exhaustive.
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
        ],
    });
});
