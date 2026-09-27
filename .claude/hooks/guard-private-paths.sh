#!/bin/bash
#
# PreToolUse hook (Bash matcher): blocks a forced `git add` of docs/plans/ or
# _noupload/. Both are gitignored because the repository is public and they
# hold private working notes (plans, spike captures with member email
# addresses, audit reports). .gitignore stops a plain `git add`, but
# `git add -f` / `--force` bypasses it, and nothing else stopped that.
#
# There is no override: these paths are never committed. Move the content to
# a committed location first if it must be public.
#
set -uo pipefail

input="$(cat)"

if ! cmd="$(printf '%s' "$input" | jq -r '.tool_input.command // empty' 2>/dev/null)"; then
  echo '{"hookSpecificOutput":{"hookEventName":"PreToolUse","permissionDecision":"deny","permissionDecisionReason":"guard-private-paths.sh could not parse hook input (jq failure) — denying by default."}}'
  exit 0
fi

# Look at each `git add` segment of a compound command on its own, so a
# flag in one command cannot pair with a path in another.
while IFS= read -r seg; do
  # The segment must BE a git add (optionally after VAR=value prefixes or
  # `git -C dir`), not merely mention one inside a quoted message.
  echo "$seg" | grep -qE '^[[:space:]]*([A-Za-z_][A-Za-z0-9_]*=[^[:space:]]*[[:space:]]+)*git([[:space:]]+-C[[:space:]]+[^[:space:]]+)?[[:space:]]+add\b' || continue
  echo "$seg" | grep -qE '(^|[[:space:]])(-[a-zA-Z]*f[a-zA-Z]*|--force)([[:space:]]|$)' || continue
  if echo "$seg" | grep -qE '(^|[[:space:]"'\''/])(\./)?(docs/plans|_noupload)(/|[[:space:]"'\'']|$)'; then
    echo '{"hookSpecificOutput":{"hookEventName":"PreToolUse","permissionDecision":"deny","permissionDecisionReason":"Forced git add of docs/plans/ or _noupload/ is blocked. These directories hold private notes and are never committed (the repository is public). See .claude/rules/planning-docs.md."}}'
    exit 0
  fi
done < <(printf '%s\n' "$cmd" | sed -E 's/(&&|\|\||;|\|)/\n/g')

exit 0
