#!/usr/bin/env bash
# Run the promptfoo suite against the Mantia agent's system prompt.
#
#   bin/promptfoo.sh          # full suite + open the UI on a result hit
#   bin/promptfoo.sh --quiet  # CI mode, no UI
#
# Requires: ANTHROPIC_API_KEY in env (set via .env or shell export).
# Uses: npx promptfoo (no install needed; cached after first run).
set -euo pipefail

PROJECT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"
cd "$PROJECT_DIR/promptfoo"

if [[ -z "${ANTHROPIC_API_KEY:-}" ]]; then
  echo "ANTHROPIC_API_KEY not set. Export it or add to ~/.zshrc." >&2
  exit 2
fi

ARGS=("eval" "-c" "promptfooconfig.yaml" "--no-cache")
if [[ "${1:-}" == "--quiet" ]]; then
  npx --yes promptfoo@latest "${ARGS[@]}"
else
  npx --yes promptfoo@latest "${ARGS[@]}"
  echo ""
  echo "→ Open the interactive results viewer:"
  echo "   cd promptfoo && npx promptfoo view"
fi
