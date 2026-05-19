#!/usr/bin/env bash
# Wipe QA test data (users / groups / predictions starting with phone
# prefix 9999000) on prod. Safe to re-run; never touches real users.
#
#   bin/qa-cleanup.sh
set -euo pipefail
SSH_TARGET="${MANTIA_SSH:-mantia3.wordpress.com@ssh.wp.com}"
PLUGIN_PATH="${MANTIA_PLUGIN_PATH:-wp-content/plugins/mantia}"
ssh "$SSH_TARGET" "cd htdocs && wp eval-file $PLUGIN_PATH/bin/qa-cleanup.php"
