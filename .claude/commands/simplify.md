---
description: Simplify recently modified code for clarity without changing behavior
model: claude-haiku-4-5
---

# Simplify

Think hard about whether each proposed simplification preserves behavior
exactly — including edge cases, error paths, and side effects.

Keep output brief — terse status lines, no preamble, no restating of steps.

Review the recently modified code for opportunities to simplify, improve
clarity, reduce redundancy, and align with project coding standards — without
changing any behavior. Focus only on files changed in the current session
unless instructed otherwise.

Use the Agent tool with `subagent_type: "code-simplifier"` to perform the
review and apply refinements. (The project-level `code-simplifier` agent runs
on Sonnet; this command itself is a thin wrapper, so Haiku is sufficient.)
