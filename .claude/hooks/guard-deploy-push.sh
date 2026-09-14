#!/bin/bash
#
# PreToolUse hook (Bash matcher): asks for confirmation before any
# `git push prod ...` or `git push test ...` — these deploy remotes hit
# elanregistry.org / test.elanregistry.org directly. See CLAUDE.md
# "Quick Deployment Reference" — prod/test are never origin (GitHub).
#
input="$(cat)"
cmd="$(echo "$input" | jq -r '.tool_input.command // empty')"

if echo "$cmd" | grep -qE '\bgit[[:space:]]+push[[:space:]]+(prod|test)\b'; then
  echo '{"hookSpecificOutput":{"hookEventName":"PreToolUse","permissionDecision":"ask","permissionDecisionReason":"Pushing to a deploy remote (prod/test) hits the live site. Confirm this was explicitly requested."}}'
  exit 0
fi

exit 0
