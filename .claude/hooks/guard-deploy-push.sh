#!/bin/bash
#
# PreToolUse hook (Bash matcher): blocks any `git push prod ...` or
# `git push test ...` — these deploy remotes hit elanregistry.org /
# test.elanregistry.org directly. See CLAUDE.md "Quick Deployment
# Reference" — prod/test are never origin (GitHub).
#
# CLAUDE.md states this as a hard rule ("Never push to prod or test
# without being asked"), so this is `deny`, not `ask` — a soft prompt can
# resolve on its own under an auto-accept permission mode, which defeats
# the point for a command that deploys straight to the live site.
#
# Override: prefix the command with `DEPLOY_CONFIRMED=1` (e.g. for a
# deliberate, user-requested deploy, including mid-debugging). The marker
# must be explicitly typed for this specific command — it is not a
# standing exemption, so don't leave it in a script or alias.
#
input="$(cat)"
cmd="$(echo "$input" | jq -r '.tool_input.command // empty')"

if echo "$cmd" | grep -qE '\bgit[[:space:]]+push[[:space:]]+(prod|test)\b'; then
  if echo "$cmd" | grep -qE '(^|[;&|[:space:]])DEPLOY_CONFIRMED=1([[:space:]]|$)'; then
    exit 0
  fi
  echo '{"hookSpecificOutput":{"hookEventName":"PreToolUse","permissionDecision":"deny","permissionDecisionReason":"Pushing to a deploy remote (prod/test) hits the live site and is blocked by default. If this push was explicitly requested, re-run it with DEPLOY_CONFIRMED=1 prefixed to the command, e.g.: DEPLOY_CONFIRMED=1 git push prod vX.Y.Z"}}'
  exit 0
fi

exit 0
