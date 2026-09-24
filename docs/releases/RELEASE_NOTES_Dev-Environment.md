# Elan Registry Dev Environment Release Notes

**Release Date:** TBD
**Type:** Patch Release - Developer Tooling

## User-Facing Changes

None — this milestone is developer-facing tooling only.

## Issues Resolved

- [#2160](https://github.com/elan-registry/registry/issues/2160) — The blocking pre-push
  integration gate runs in about 20 seconds instead of five minutes, no longer runs
  live-network tests, and now also catches gated files that were renamed away.
- WIP: [#2161](https://github.com/elan-registry/registry/issues/2161) — Second-pass cleanup of low-signal tests in `tests/integration/`.
