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
# Preview what would be removed (safe dry-run)
./scripts/cleanup-outdated-docs.sh --dry-run

# Actually remove files (from current project)
./scripts/cleanup-outdated-docs.sh

# Run on production server (dry-run first!)
./scripts/cleanup-outdated-docs.sh /home/unibrain/elanregistry.org --dry-run

# Run on production server (live removal)
./scripts/cleanup-outdated-docs.sh /home/unibrain/elanregistry.org

# Get help
./scripts/cleanup-outdated-docs.sh --help
```

**Options:**

- `--dry-run` - Preview changes without removing any files (RECOMMENDED FIRST)
- `--help`, `-h` - Show usage information
- `PROJECT_ROOT` - Specify custom project path (default: auto-detect)

**Safety:**

- **Always run with `--dry-run` first** to preview changes
- Safe to run multiple times (idempotent)
- Only removes specifically identified outdated files
- Reports what it found and what it removed
- Can be recovered with `git checkout HEAD -- <filename>` if needed

**Example output (dry-run):**

```text
═══════════════════════════════════════════════════════════
  Outdated Documentation Cleanup Script
═══════════════════════════════════════════════════════════

Project Root: /home/unibrain/elanregistry.org
Mode: DRY RUN (preview only, no files will be removed)

Checking for outdated files...

Found outdated file: ./RELEASE_NOTES_V2.8.1.md
  → Moved to: ./docs/releases/RELEASE_NOTES_V2.8.1.md
⚠  WOULD REMOVE: ./RELEASE_NOTES_V2.8.1.md

═══════════════════════════════════════════════════════════
  Cleanup Summary
═══════════════════════════════════════════════════════════
Would remove:    2 files
Already missing: 2 files
Kept:            0 files

✓ Dry-run complete!

To actually remove these files, run:
  ./scripts/cleanup-outdated-docs.sh /home/unibrain/elanregistry.org
```

**Production deployment workflow:**

```bash
# 1. SSH into production server
ssh user@production-server

# 2. Navigate to project directory
cd /home/unibrain/elanregistry.org

# 3. Run dry-run to preview changes
./scripts/cleanup-outdated-docs.sh --dry-run

# 4. Review output carefully

# 5. If everything looks correct, run live removal
./scripts/cleanup-outdated-docs.sh

# 6. Verify cleanup completed successfully
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
