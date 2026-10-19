---
name: hotfix
description: "Run the hotfix procedure for a production emergency: production is broken, data is at risk, or a security exposure exists. Use when the user starts a hotfix or asks how to handle an urgent production defect."
---

# Hotfix

Use this procedure when production is broken, data is at risk, or a security
exposure exists.

1. Capture the issue with `/new-issue` (`signal:defect`). During another
   issue, use `/found`, which records the paused issue.
2. `/start-issue <N> --hotfix` branches from `main`. The per-issue commands
   then run as usual, with PR base `main`.
3. Do the patch release in `DEPLOYMENT.md`, "Patch Release from main".
4. To resume a paused issue, type `/start-issue <paused-N>`. `/finish-issue`
   names it.
