---
paths:
  - "docs/**/*.md"
  - "README.md"
---

# Documentation

- Write for two mid-level software engineers.
- Document the UserSpice input rule correctly. Use `ElanRegistry\Input::raw()`
  for database values. Never use `\Input::get()` for them. It pre-encodes the
  value, which causes double encoding. See `CLAUDE.md`.
- Before you document UserSpice behavior, check the ElanRegistry overrides. See
  `CLAUDE.md`, "UserSpice context".
