#!/usr/bin/env bash
# QA platform runner — orchestrates the persona agents + reviewer.
#
# This script is intentionally thin: the actual agent spawning happens
# inside a Claude Code session (the Agent tool can't be invoked from a
# plain bash script). Use this to set up the run, then drive from Claude.
#
#   bin/qa-run.sh prep     # clean test data, deploy bin/qa-sim.php
#   bin/qa-run.sh status   # show counts of QA data on prod
#   bin/qa-run.sh dashboard # rebuild dashboard.html from latest findings.json files
set -euo pipefail
PROJECT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"
cd "$PROJECT_DIR"

CMD="${1:-help}"

case "$CMD" in
  prep)
    echo "→ Deploying simulator + cleanup scripts to prod..."
    rsync -avz -e "ssh -o ConnectTimeout=15" \
      ./bin/qa-sim.php ./bin/qa-cleanup.php \
      mantia3.wordpress.com@ssh.wp.com:htdocs/wp-content/plugins/mantia/bin/ >/dev/null
    echo "→ Wiping any leftover QA test data (phone prefix 9999000)..."
    bin/qa-cleanup.sh
    echo "→ Ready. Now spawn the persona agents from a Claude Code session:"
    echo "   tests/qa-personas/01-organizer-cold.md   → persona 1"
    echo "   tests/qa-personas/02-organizer-returning.md → persona 2"
    echo "   tests/qa-personas/03-member-invited.md   → persona 3"
    echo "   tests/qa-personas/04-multi-penca.md      → persona 4"
    echo "   tests/qa-personas/05-lurker-web-only.md  → persona 5"
    ;;
  status)
    ssh mantia3.wordpress.com@ssh.wp.com "cd htdocs && \
      QA_USERS=\$(wp post list --post_type=mantia_user --meta_key=_mantia_phone --meta_value='9999000' --meta_compare='LIKE' --format=count); \
      QA_GROUPS=\$(wp post list --post_type=mantia_group --format=count); \
      QA_PREDS=\$(wp post list --post_type=mantia_prediction --format=count); \
      echo \"qa_users=\$QA_USERS  groups=\$QA_GROUPS  preds=\$QA_PREDS\""
    ;;
  dashboard)
    if [[ ! -d tests/qa-output ]] || ! ls tests/qa-output/*-findings.json >/dev/null 2>&1; then
      echo "No findings to render. Run the agents first." >&2
      exit 1
    fi
    php bin/qa-dashboard.php > tests/qa-output/dashboard.html
    echo "→ Wrote tests/qa-output/dashboard.html"
    ;;
  *)
    echo "Usage: bin/qa-run.sh {prep|status|dashboard}"
    exit 2
    ;;
esac
