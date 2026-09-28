---
description: Repo-specific PR monitoring cadence and conventions for Elan Registry
---

# Babysitting PRs on this repo

This file is read (per the harness's own PR-babysitting rules) before acting
on CI or review events for a PR you opened or drive for its author. It sets
conventions and cadence; it does not loosen any rule stated as "never" in
those rules.

## Check-in cadence

- **Draft PR, no reviewer requested, no CI failures, no open review
  threads:** this is not on anyone's clock — the author hasn't asked for
  eyes yet. Check every 3-4 hours, not hourly. Don't burn a full check-in
  cycle re-reading identical empty state (same draft flag, same clean CI,
  same zero comments) more than once an hour.
- **Ready for review, or CI is red, or a review thread is open:** the
  default hourly cadence applies as normal — someone may be waiting on you.
- Switching cadence (e.g., a draft PR gets marked ready, or a comment
  lands) means switching back to hourly on the very next event, not waiting
  for the next long interval to catch up.

## Scope conventions

See `CLAUDE.md`'s "PR Scope" section — split a PR when it bundles concerns
that trigger different review gates (e.g., `.github/workflows/` changes,
which need human review under this repo's self-referential CI guard, vs.
`.claude/commands/*.md` frontmatter, which doesn't).
