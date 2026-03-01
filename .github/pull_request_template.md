# Pull Request Template

## PR Title Format

**Use this format to ensure consistent naming and auto-closure of related issues:**

```text
[Type] #[Issue]: [Brief Description] (#[PR Number])
```

**Example:** `Fix #581: Standardize Registry Link AJAX endpoint path and add CSRF token (#589)`

**Type Options:**

- `Fix` - Bug fix or issue resolution
- `Add` - New feature or functionality
- `Update` - Enhancement to existing feature
- `Refactor` - Code restructuring without behavior change
- `Docs` - Documentation updates
- `Chore` - Maintenance, dependencies, tooling

## Summary

**Related Issue(s):** #XXX

**Description:**
Brief description of the changes made and why they were necessary.

## Changes Made

- Change 1
- Change 2
- Change 3

## Testing

Describe how this was tested:

- [ ] Unit tests added/updated
- [ ] Manual testing completed
- [ ] Tested in browser (if UI change)
- [ ] Existing tests pass

## Checklist

Before merging, ensure:

- [ ] PR title follows format: `[Type] #XXX: Description (#PR)`
- [ ] Related issue number is in the title (auto-closes when merged)
- [ ] Code follows [CODING_STANDARDS.md](docs/development/CODING_STANDARDS.md)
- [ ] PHP 8+ type hints and return types included
- [ ] All new public methods have PHPDoc blocks
- [ ] Tests added/updated for new functionality
- [ ] No hardcoded credentials or sensitive data
- [ ] Database changes include migration files
- [ ] Release notes updated (see [RELEASE_NOTES_TEMPLATE.md](docs/development/RELEASE_NOTES_TEMPLATE.md))
- [ ] All tests pass locally: `composer test:full`
- [ ] Security review completed (if applicable)

## Related Documentation

- [CODING_STANDARDS.md](docs/development/CODING_STANDARDS.md) - Code quality requirements
- [ERROR_HANDLING.md](docs/development/ERROR_HANDLING.md) - Error handling patterns
- [RELEASE_NOTES_TEMPLATE.md](docs/development/RELEASE_NOTES_TEMPLATE.md) - Release notes format
- [CLAUDE.md](CLAUDE.md) - Development guidelines
