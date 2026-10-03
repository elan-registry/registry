#!/bin/bash
#
# PostToolUse hook (Edit|Write matcher): runs PHPStan on a PHP file right
# after Claude edits it, so a new error surfaces at write time instead of at
# /execute-plan, /finish-issue, or /review-pr. The baseline suppresses
# pre-existing errors, so anything reported is new (see
# .claude/rules/phpstan.md).
#
# Output: on new errors, exit 2 with the errors on stderr — Claude Code shows
# stderr to Claude after a PostToolUse exit 2. The edit itself is not undone.
# Any other failure (no vendor/, PHPStan crash, file outside analysis scope)
# exits 0 silently: this hook is an early warning, not the gate. The
# pre-commit hook and CI remain the gate.
#
set -uo pipefail

input="$(cat)"
file="$(printf '%s' "$input" | jq -r '.tool_input.file_path // empty' 2>/dev/null)" || exit 0
[ -n "$file" ] || exit 0

root="${CLAUDE_PROJECT_DIR:-$PWD}"
rel="${file#"$root"/}"

case "$rel" in
  *.php) ;;
  *) exit 0 ;;
esac

# Same scope as phpstan.neon: project-owned code only. Upstream UserSpice
# files and untracked plugin/template copies are not analysed.
case "$rel" in
  /*|users/*|vendor/*|node_modules/*|_noupload/*|docs/plans/*) exit 0 ;;
  usersc/plugins/hooker/hooks/*|usersc/plugins/ai_prompts/custom_prompts/*) ;;
  usersc/plugins/*|usersc/templates/*|usersc/widgets/*) exit 0 ;;
esac

[ -f "$root/$rel" ] || exit 0
[ -x "$root/vendor/bin/phpstan" ] || exit 0

cd "$root" || exit 0
out="$(vendor/bin/phpstan analyse --no-progress --error-format=raw --memory-limit=1G -- "$rel" 2>&1)"
status=$?

# PHPStan exits 1 when it reports errors. Other codes mean it could not run.
# Raw format prints absolute paths ("/abs/path/file.php:LINE:message").
if [ "$status" -eq 1 ] && printf '%s' "$out" | grep -qF "$rel:"; then
  {
    echo "PHPStan found new errors in $rel (baseline errors are suppressed, so these are new):"
    printf '%s\n' "$out" | grep -F "$rel:" | sed "s|^$root/||" | head -20
    echo "Resolve them before you continue (.claude/rules/phpstan.md)."
  } >&2
  exit 2
fi

exit 0
