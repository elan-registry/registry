#!/bin/bash
#
# PreToolUse hook (Edit|Write matcher): flags edits to directories CLAUDE.md
# marks as upstream (UserSpice core / templates / plugins) or archived, with
# the project-owned exceptions carved out. See CLAUDE.md "Template
# Customization Rules" and Web/ElanRegistry/CLAUDE.md "Do not modify
# Type26Archive/".
#
input="$(cat)"
file="$(echo "$input" | jq -r '.tool_input.file_path // empty')"
rel="${file#"$PWD"/}"

case "$rel" in
  users/*)
    echo '{"hookSpecificOutput":{"hookEventName":"PreToolUse","permissionDecision":"ask","permissionDecisionReason":"users/ is upstream UserSpice framework — do not modify directly. Extend via usersc/classes/ instead (see CLAUDE.md)."}}'
    exit 0
    ;;
  Type26Archive/*)
    echo '{"hookSpecificOutput":{"hookEventName":"PreToolUse","permissionDecision":"deny","permissionDecisionReason":"Type26Archive/ is a preserved mirror — never modified (see Web/ElanRegistry/CLAUDE.md)."}}'
    exit 0
    ;;
  usersc/templates/customizer/file_nav_custom.php|usersc/templates/customizer/assets/child_themes/elanregistry*|usersc/templates/customizer/assets/child_themes/dashboard.php|usersc/templates/customizer.css|usersc/templates/customizer/navigation.php)
    exit 0
    ;;
  usersc/templates/*)
    echo '{"hookSpecificOutput":{"hookEventName":"PreToolUse","permissionDecision":"ask","permissionDecisionReason":"usersc/templates/ is upstream except for a few tracked exceptions (file_nav_custom.php, elanregistry child theme, customizer.css). Confirm this file is one of them."}}'
    exit 0
    ;;
  usersc/plugins/*)
    case "$rel" in
      usersc/plugins/hooker/hooks/*|usersc/plugins/ai_prompts/custom_prompts/*)
        exit 0
        ;;
      *)
        echo '{"hookSpecificOutput":{"hookEventName":"PreToolUse","permissionDecision":"ask","permissionDecisionReason":"usersc/plugins/ is upstream except hooker/hooks/ and ai_prompts/custom_prompts/. Confirm this file is a tracked exception."}}'
        exit 0
        ;;
    esac
    ;;
esac

exit 0
