#!/usr/bin/env bash
# UX detective — promptfoo-driven web-surface checks against mantia3.
#
# Two-step flow:
#   bin/promptfoo-ux.sh setup   # reset prod + create Alice + emit vars
#   bin/promptfoo-ux.sh run     # eval all promptfoo assertions
#   bin/promptfoo-ux.sh view    # open the result viewer
#
# The setup step writes one-line var files under promptfoo/ux/vars/ that
# the YAML config loads via file:// references. Re-run setup whenever
# the canonical state needs refreshing (or just run `all` to do both
# back-to-back in a single command).

set -uo pipefail

# Promptfoo needs Node >= 22.22 or ^20.20; this Mac defaults to 22.14
# (older). Source nvm + switch if available; otherwise the npx call
# fails with a clear "supported Node.js runtime" error.
if [ -s "$HOME/.nvm/nvm.sh" ]; then
  # shellcheck disable=SC1091
  . "$HOME/.nvm/nvm.sh"
  # Promptfoo requires ^20.20.0 or >=22.22.0. Prefer 22.22+ (matches the
  # version range posthog-node + mute-stream demand too). Falls back to
  # 20.20 if 22.22 isn't installed locally.
  if nvm version 22.22.0 >/dev/null 2>&1; then
    nvm use 22.22.0 >/dev/null 2>&1 || true
  elif nvm version 20.20.0 >/dev/null 2>&1; then
    nvm use 20.20.0 >/dev/null 2>&1 || true
  fi
fi

SSH_HOST="${MANTIA_SSH:-mantia3.wordpress.com@ssh.wp.com}"
PLUGIN_PATH="wp-content/plugins/mantia"
PROJECT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"
UX_DIR="$PROJECT_DIR/promptfoo/ux"
VARS_DIR="$UX_DIR/vars"
mkdir -p "$VARS_DIR"

cmd="${1:-all}"

setup_state() {
  echo "▶ resetting + seeding canonical UX state on $SSH_HOST"
  ssh -o ConnectTimeout=15 "$SSH_HOST" "mkdir -p /srv/htdocs/${PLUGIN_PATH}/tests/ux" >/dev/null
  # Always deploy the latest setup script (matches the running plugin).
  rsync -avz -e "ssh -o ConnectTimeout=15" \
    "$PROJECT_DIR/tests/ux/setup-canonical-user.php" \
    "$SSH_HOST:/srv/htdocs/${PLUGIN_PATH}/tests/ux/setup-canonical-user.php" \
    >/dev/null

  local out
  out=$(ssh -o ConnectTimeout=15 "$SSH_HOST" "cd /srv/htdocs \
    && wp eval-file ${PLUGIN_PATH}/tools/reset-users-and-groups.php 2>&1 | tail -1 \
    && wp eval-file ${PLUGIN_PATH}/tests/ux/setup-canonical-user.php 2>&1")

  if echo "$out" | grep -q "^ERROR:"; then
    echo "$out" | grep "^ERROR:" >&2
    return 2
  fi

  get_val() {
    echo "$out" | grep -m1 "^$1:" | sed "s/^$1: //"
  }

  # Persist each var as a one-line file the YAML's file:// loader reads.
  echo -n "https://mantia3.wpcomstaging.com" > "$VARS_DIR/base_url.txt"
  echo -n "$(get_val SHARE_TOKEN)"             > "$VARS_DIR/share_token.txt"
  echo -n "$(get_val VIEW_TOKEN)"              > "$VARS_DIR/view_token.txt"
  echo -n "$(get_val GROUP_NAME)"              > "$VARS_DIR/group_name.txt"
  echo -n "$(get_val INVITE_CODE)"             > "$VARS_DIR/invite_code.txt"
  echo -n "libertadores-prueba"                > "$VARS_DIR/comp_slug.txt"

  echo "  · canary state ready: $(get_val GROUP_NAME) (code $(get_val INVITE_CODE))"
  echo "  · vars written to $VARS_DIR/"
}

run_eval() {
  cd "$UX_DIR"
  if ! [ -s "$VARS_DIR/share_token.txt" ]; then
    echo "no vars on disk — run \`bin/promptfoo-ux.sh setup\` first" >&2
    return 2
  fi
  echo "▶ evaluating UX invariants via promptfoo"
  npx --yes promptfoo@latest eval -c promptfooconfig.yaml --no-cache "$@"
}

view_results() {
  cd "$UX_DIR"
  npx --yes promptfoo@latest view
}

case "$cmd" in
  setup)
    setup_state
    ;;
  run)
    shift
    run_eval "$@"
    ;;
  view)
    view_results
    ;;
  all)
    setup_state && run_eval
    ;;
  *)
    echo "usage: $0 {setup|run|view|all}" >&2
    exit 2
    ;;
esac
