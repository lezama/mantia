#!/usr/bin/env bash
# Single-shot WhatsApp simulator for the QA platform.
#
# Sends one message as the given test persona and prints the bot reply as
# JSON. Always targets prod (mantia3.wpcomstaging.com) since the QA agents
# decided to run there with the 9999000 phone prefix.
#
#   bin/sim-wa.sh '+999900001' 'QA Owner' 'hola'
#
# For multi-step flows, prefer bin/sim-wa-batch.sh — single-shot pays one
# SSH round trip per message; batch amortizes that across N ops.
set -euo pipefail

if [[ $# -lt 3 ]]; then
  echo "Usage: $0 <phone> <name> <message>" >&2
  exit 2
fi

PHONE="$1"
NAME="$2"
MESSAGE="$3"
SSH_TARGET="${MANTIA_SSH:-mantia3.wordpress.com@ssh.wp.com}"
PLUGIN_PATH="${MANTIA_PLUGIN_PATH:-wp-content/plugins/mantia}"

# Strip leading + if present — Mantia stores E.164 without the +.
PHONE="${PHONE#+}"

PAYLOAD=$(printf '{"operations":[{"type":"send","phone":"%s","name":"%s","message":%s}]}' \
  "$PHONE" "$NAME" "$(printf '%s' "$MESSAGE" | python3 -c 'import json,sys; print(json.dumps(sys.stdin.read()))')")

echo "$PAYLOAD" | ssh "$SSH_TARGET" "cd htdocs && wp eval-file $PLUGIN_PATH/bin/qa-sim.php"
