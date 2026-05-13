#!/usr/bin/env bash
# Run Mantia E2E tests against a remote install (default: mantia3 via wp.com SSH).
#
# Usage:
#   bin/e2e.sh                  # default target = mantia3.wordpress.com@ssh.wp.com
#   MANTIA_SSH=...@ssh.wp.com bin/e2e.sh
#   MANTIA_TEST=cleanup bin/e2e.sh   # just run cleanup, no scenario
#
# Syncs tests/ + bin/ to the remote plugin dir, then runs the scenario via
# wp eval-file. Every persona's "WhatsApp message" goes through the same
# preflight openclaWP uses for real traffic — no mocks.
set -euo pipefail

MANTIA_SSH="${MANTIA_SSH:-mantia3.wordpress.com@ssh.wp.com}"
MANTIA_TEST="${MANTIA_TEST:-penca-lifecycle}"
PLUGIN_PATH="htdocs/wp-content/plugins/mantia"

PROJECT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"

echo "▶ Syncing tests/ to ${MANTIA_SSH}:${PLUGIN_PATH}/tests/"
rsync -avz --delete -e "ssh -o ConnectTimeout=15" \
  "${PROJECT_DIR}/tests/" \
  "${MANTIA_SSH}:${PLUGIN_PATH}/tests/" \
  | grep -E '^(sending|>f|deleting|sent .* bytes)' || true

echo ""
echo "▶ Running tests/e2e/${MANTIA_TEST}.php"
echo ""

ssh -o ConnectTimeout=15 "${MANTIA_SSH}" \
  "cd htdocs && wp cache flush > /dev/null && wp eval-file wp-content/plugins/mantia/tests/e2e/${MANTIA_TEST}.php"
