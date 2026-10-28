---
paths:
  - "docs/plans/**"
  - "docs/development/SYSTEM_OVERVIEW.md"
---

# Product and planning

Use this file for product scope, issues, milestones, and labels.

## Project

- Lotus Elan Registry (elanregistry.org) is a PHP application on UserSpice.
- Users are Lotus Elan car owners who:
  - register and document their cars (chassis numbers, specifications,
    history),
  - transfer ownership when cars are sold,
  - research car history and provenance,
  - connect with other owners,
  - use documentation and resources (paint colors, technical specs, FAQs).

## Domain concepts

- Owners: users with accounts and profiles.
- Cars: chassis numbers, model years, specifications.
- Transfers: ownership changes that need validation and approval.
- Chassis validation: authentic, correctly formatted chassis numbers.
- Paint colors: historical accuracy for restoration.
- Documentation system: Markdown FAQ and technical docs.

## Priorities

- For a car registry that handles owner data, security and GDPR compliance are
  non-negotiable.
- In attack prevention, name CSRF and SQL injection.
- Framework upgrades (UserSpice updates) are a dependency to track.
- Strategic alignment example: historical accuracy.

## Planning and labels

- `/flow:plan-milestone` seals the issue list. Work outside it goes to a later
  milestone, or to a patch release from `main` when production is broken.
- List labels with `gh label list -R elan-registry/registry`.
- Label families:
  - Type: `bug`, `enhancement`, `refactor`, `tech-debt`.
  - Area: `component: *` (for example `component: security`, `component: ui`).
  - Signal and status: `signal: *`, `status: *`, `triage`, `gate-critical`.

## Registry examples

Use these Registry examples in acceptance criteria, decomposition, and
interview questions:

- CSV export of a car's maintenance history.
- Paint color FAQ page.
- Transfer request form with chassis validation.
- "All car models or specific ones".
- "Archived/sold cars".
- Invalid chassis number.
- "Improve car search".
- Overlap with the issue about paint colors.
