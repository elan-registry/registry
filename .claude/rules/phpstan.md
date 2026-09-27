---
paths:
  - "**/*.php"
  - "phpstan.neon"
  - "phpstan-baseline.neon"
---

# PHPStan hygiene

When you change any PHP file in `app/`, `usersc/`, or any other path listed
in `phpstan.neon`, run PHPStan on it and resolve **all** errors it reports.
The baseline silently suppresses pre-existing errors, so anything reported is
new.

```bash
vendor/bin/phpstan analyse <file>   # check the file you touched
composer phpstan:baseline           # regenerate baseline after fixing
```

Treat pre-existing baseline errors as debt and resolve them in files you
change. `reportUnmatchedIgnoredErrors: true` makes CI reject a stale baseline
entry after you fix its error. See `docs/development/CODING_STANDARDS.md`
(PHPStan Baseline Hygiene).

`.claude/hooks/phpstan-on-edit.sh` runs this check after each edit to a
project PHP file and reports new errors at once. The pre-commit hook and CI
are still the gate.
