# Scripts Directory

Utility scripts for the Elan Registry project.

## Cleanup Scripts

### cleanup-outdated-docs.sh

**Purpose:** Removes outdated markdown documentation files that have been
moved or are no longer needed.

**When to use:**

- After deploying documentation restructuring changes to production
- When release notes have been moved from root to `docs/releases/`
- When cleaning up old release plan files

**What it does:**

- Removes root-level `RELEASE_NOTES_V*.md` files (moved to `docs/releases/`)
- Removes old `docs/development/*RELEASE_PLAN*.md` files (superseded by
  version-specific test plans in `docs/technical/`)
- Keeps intentional files like `CLAUDE.md` (redirect) and `README.md`
- Reports what was removed and suggests proper locations

**Files targeted for removal:**

```bash
./RELEASE_NOTES_V2.8.1.md      # → docs/releases/RELEASE_NOTES_V2.8.1.md
./RELEASE_NOTES_V2.8.6.md      # → docs/releases/RELEASE_NOTES_V2.8.6.md
./docs/development/V2.8.1_RELEASE_PLAN.md  # Outdated release plan
./docs/development/RELEASE_PLAN.md         # Outdated generic template
```

**Files preserved:**

- `./CLAUDE.md` - Intentional redirect to `docs/development/CLAUDE.md`
- `./README.md` - Main project README

**Usage:**

```bash
# Run from project root
./scripts/cleanup-outdated-docs.sh

# Or from anywhere
bash /path/to/scripts/cleanup-outdated-docs.sh
```

**Safety:**

- Safe to run multiple times (idempotent)
- Only removes specifically identified outdated files
- Reports what it found and what it removed
- Can be recovered with `git checkout HEAD -- <filename>` if needed

**Example output:**

```text
═══════════════════════════════════════════════════════════
  Outdated Documentation Cleanup Script
═══════════════════════════════════════════════════════════

Checking for outdated files...

Found outdated file: ./RELEASE_NOTES_V2.8.1.md
  → Moved to: ./docs/releases/RELEASE_NOTES_V2.8.1.md
✗ REMOVED: ./RELEASE_NOTES_V2.8.1.md

═══════════════════════════════════════════════════════════
  Cleanup Summary
═══════════════════════════════════════════════════════════
Removed:         2 files
Already missing: 2 files
Kept:            0 files
```

## Other Scripts

### setup-git-hooks.sh

Sets up Git pre-commit hooks for code quality checks (PHP linting, markdown
linting, etc.)

### bump-version.sh

Updates version numbers across the project for new releases.

---

## Adding New Scripts

When adding new scripts:

1. Place them in the `scripts/` directory
2. Make them executable: `chmod +x scripts/your-script.sh`
3. Add documentation to this README
4. Include usage examples and safety notes
5. Consider adding a `--help` flag
6. Use proper error handling with `set -e`
