/**
 * Local ESLint rule: enforces that test.skip()/testInfo.skip() always
 * includes a reason string. A bare skip() or single-arg skip('reason')
 * makes CI report a false "passed" instead of "skipped" (#1949, #1950). See
 * CLAUDE.md's "Playwright Test Maintenance" section.
 *
 * Extracted to its own CJS module (rather than defined inline in
 * eslint.config.mjs) so tests/eslint-require-skip-reason.test.js can
 * require() the exact rule object ESLint loads, instead of testing a
 * hand-copied duplicate that could silently diverge from the real rule.
 */
module.exports = {
    create(context) {
        return {
            CallExpression(node) {
                const callee = node.callee;
                if (
                    callee.type === "MemberExpression" &&
                    callee.object.type === "Identifier" &&
                    (callee.object.name === "test" || callee.object.name === "testInfo") &&
                    callee.property.type === "Identifier" &&
                    callee.property.name === "skip" &&
                    node.arguments.length < 2
                ) {
                    context.report({
                        node,
                        message:
                            "skip() must include a second argument " +
                            "(a reason string): use test.skip(condition, " +
                            "'reason') or testInfo.skip(true, 'reason') — " +
                            "see CLAUDE.md 'Playwright Test Maintenance' " +
                            "and issue #1950.",
                    });
                }
            },
        };
    },
};
