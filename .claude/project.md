# Project facts for devkit commands

The flow commands read this file. Claude Code does not load it in a normal
session. Each fact is one line: a dash, the key, a colon, and the value in
backticks. The keys are in
<https://github.com/unibrain1/devkit/blob/main/docs/project-md.md>.

- test: `docker compose exec -u www-data app composer test:full`
- test_quick: `composer test:quick`
- lint: `composer check`
- verify: `scripts/run-verification-suite.sh`
- deploy: see `docs/development/DEPLOYMENT.md`
- standards: `docs/development/CODING_STANDARDS.md`
- plans: `docs/plans`
- release_notes: `docs/releases/RELEASE_NOTES_{version}.md`
- ledger: `docs/development/CLEANUP_LEDGER.md`
- private_paths: `docs/plans _noupload`
- issue_checks: `scripts/check-baseline-hygiene.sh`
- milestone_checks: `scripts/check-deploy-sheet-fresh.sh`
- release_checks: `scripts/check-deploy-sheet-fresh.sh`
- release: `draft`
