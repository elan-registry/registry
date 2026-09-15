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
# Override: prefix the ACTUAL push command with `DEPLOY_CONFIRMED=1`, e.g.
# `DEPLOY_CONFIRMED=1 git push prod vX.Y.Z`. The marker must immediately
# precede the git push invocation being matched — a plain substring match
# anywhere in the command text (a quoted commit message, a code comment, an
# unrelated earlier command in a compound) would let the marker "leak" into
# commands it was never meant to authorize, which defeats the "explicitly
# typed for this specific command" contract this override is supposed to
# have.
#
set -uo pipefail

input="$(cat)"

if ! cmd="$(printf '%s' "$input" | jq -r '.tool_input.command // empty' 2>/dev/null)"; then
  # Fail closed: if the hook can't even parse its own input, deny rather
  # than silently fall through to the unconditional exit 0 below. A guard
  # hook that fails open on malformed input is worse than no hook at all.
  echo '{"hookSpecificOutput":{"hookEventName":"PreToolUse","permissionDecision":"deny","permissionDecisionReason":"guard-deploy-push.sh could not parse hook input (jq failure) — denying by default rather than risking a silent bypass."}}'
  exit 0
fi

if echo "$cmd" | grep -qE '(^|[;&|[:space:]])DEPLOY_CONFIRMED=1[[:space:]]+git[[:space:]]+push[[:space:]]+(prod|test)\b'; then
  exit 0
fi

if echo "$cmd" | grep -qE '\bgit[[:space:]]+push[[:space:]]+(prod|test)\b'; then
  echo '{"hookSpecificOutput":{"hookEventName":"PreToolUse","permissionDecision":"deny","permissionDecisionReason":"Pushing to a deploy remote (prod/test) hits the live site and is blocked by default. If this push was explicitly requested, re-run it with DEPLOY_CONFIRMED=1 immediately prefixing the push command, e.g.: DEPLOY_CONFIRMED=1 git push prod vX.Y.Z"}}'
  exit 0
fi

exit 0
