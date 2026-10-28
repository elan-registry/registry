---
paths:
  - "**/*.php"
---

# UserSpice work

- Before you change UserSpice code, read the UserSpice context in `CLAUDE.md`.
  It names the start-here prompt and the four ElanRegistry override files.
- Where two rules conflict, the ElanRegistry overrides win.
- Check UserSpice before you write custom code. UserSpice provides
  authentication, permissions, database operations (`$db`), the `Input` class,
  sessions, CSRF protection, email, and validation. Do not duplicate these.
- Find UserSpice helpers in `users/helpers/` and `users/classes/`. Use the
  `userspice-helper-lookup` skill to search them.
- Use `ElanRegistry\Input`, `ApiResponse`, and `LogCategories` in new code.
- For input encoding, follow `CLAUDE.md`, "Essential Development Guidelines".
