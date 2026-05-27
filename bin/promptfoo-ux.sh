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
  local script="${1:-setup-canonical-user.php}"
  echo "▶ resetting + seeding UX state ($script) on $SSH_HOST"
  ssh -o ConnectTimeout=15 "$SSH_HOST" "mkdir -p /srv/htdocs/${PLUGIN_PATH}/tests/ux" >/dev/null
  rsync -avz -e "ssh -o ConnectTimeout=15" \
    "$PROJECT_DIR/tests/ux/${script}" \
    "$SSH_HOST:/srv/htdocs/${PLUGIN_PATH}/tests/ux/${script}" \
    >/dev/null
  # setup-matrix.php re-runs deploy-libertadores-prueba.php inline to
  # refresh match kickoffs dynamically (the seed used to carry hardcoded
  # 2026-05-26..28 dates that rotted into the past). Make sure the deploy
  # script on the remote is current too.
  rsync -avz -e "ssh -o ConnectTimeout=15" \
    "$PROJECT_DIR/tools/deploy-libertadores-prueba.php" \
    "$SSH_HOST:/srv/htdocs/${PLUGIN_PATH}/tools/deploy-libertadores-prueba.php" \
    >/dev/null

  local out
  out=$(ssh -o ConnectTimeout=15 "$SSH_HOST" "cd /srv/htdocs \
    && wp eval-file ${PLUGIN_PATH}/tools/reset-users-and-groups.php 2>&1 | tail -1 \
    && wp eval-file ${PLUGIN_PATH}/tests/ux/${script} 2>&1")

  if echo "$out" | grep -q "^ERROR:"; then
    echo "$out" | grep "^ERROR:" >&2
    return 2
  fi
  # Freshness guard: setup-matrix.php emits MATCHES_PAST / STALE_MATCHES
  # lines when the libertadores fixture has stale kickoffs after the
  # seed runs. Bail loudly — proceeding would let the bot offer
  # predictions on already-played matches (2026-05-27 incident).
  if echo "$out" | grep -q "^STALE_MATCHES:"; then
    echo "$out" | grep -E "^(STALE_MATCHES|MATCHES_PAST|MATCHES_FUTURE|FIXTURE_SLOTS):" >&2
    echo "✗ libertadores-prueba fixture has past kickoffs — refusing to seed users on top of stale matches" >&2
    return 3
  fi
  # Surface the freshness summary on success too — easy to spot in logs.
  if echo "$out" | grep -q "^MATCHES_FUTURE:"; then
    echo "  · $(echo "$out" | grep '^FIXTURE_SLOTS:' | head -1 | sed 's/^FIXTURE_SLOTS: //')"
    echo "  · $(echo "$out" | grep '^MATCHES_FUTURE:' | head -1)"
  fi

  get_val() {
    echo "$out" | grep -m1 "^$1:" | sed "s/^$1: //"
  }

  # Canonical (single-user) and matrix (multi-user) fixtures share the
  # base_url var; everything else depends on which script ran.
  echo -n "https://mantia3.wpcomstaging.com" > "$VARS_DIR/base_url.txt"

  if [ "$script" = "setup-canonical-user.php" ]; then
    echo -n "$(get_val SHARE_TOKEN)" > "$VARS_DIR/share_token.txt"
    echo -n "$(get_val VIEW_TOKEN)"  > "$VARS_DIR/view_token.txt"
    echo -n "$(get_val GROUP_NAME)"  > "$VARS_DIR/group_name.txt"
    echo -n "$(get_val INVITE_CODE)" > "$VARS_DIR/invite_code.txt"
    echo -n "libertadores-prueba"    > "$VARS_DIR/comp_slug.txt"
    echo "  · canonical: $(get_val GROUP_NAME) (code $(get_val INVITE_CODE))"
  else
    echo -n "$(get_val ALICE_SHARE)" > "$VARS_DIR/alice_share.txt"
    echo -n "$(get_val BOB_SHARE)"   > "$VARS_DIR/bob_share.txt"
    echo -n "$(get_val CAROL_SHARE)" > "$VARS_DIR/carol_share.txt"
    echo -n "$(get_val LIBE_VIEW)"   > "$VARS_DIR/libe_view.txt"
    echo -n "$(get_val LIBE_NAME)"   > "$VARS_DIR/libe_name.txt"
    echo -n "$(get_val LIBE_CODE)"   > "$VARS_DIR/libe_code.txt"
    echo -n "$(get_val MUN_VIEW)"    > "$VARS_DIR/mun_view.txt"
    echo -n "$(get_val MUN_NAME)"    > "$VARS_DIR/mun_name.txt"
    echo -n "$(get_val MUN_CODE)"    > "$VARS_DIR/mun_code.txt"
    echo "  · matrix: 3 users × 2 pencas × mixed predictions ready"
  fi
  echo "  · vars written to $VARS_DIR/"
}

run_eval() {
  cd "$UX_DIR"
  if ! [ -s "$VARS_DIR/share_token.txt" ]; then
    echo "no vars on disk — run \`bin/promptfoo-ux.sh setup\` first" >&2
    return 2
  fi
  echo "▶ evaluating UX invariants via promptfoo (deterministic)"
  npx --yes promptfoo@latest eval -c promptfooconfig.yaml --no-cache "$@"
}

run_review() {
  cd "$UX_DIR"
  if ! [ -s "$VARS_DIR/share_token.txt" ]; then
    echo "no canonical vars — run \`bin/promptfoo-ux.sh setup\` first" >&2
    return 2
  fi
  if [ -z "${ANTHROPIC_API_KEY:-}" ]; then
    echo "ANTHROPIC_API_KEY not set" >&2
    return 2
  fi
  echo "▶ canonical LLM review (4 pages × Alice)"
  npx --yes promptfoo@latest eval -c promptfooconfig.review.yaml --no-cache "$@"
}

run_review_matrix() {
  cd "$UX_DIR"
  if ! [ -s "$VARS_DIR/alice_share.txt" ]; then
    echo "no matrix vars — run \`bin/promptfoo-ux.sh setup-matrix\` first" >&2
    return 2
  fi
  if [ -z "${ANTHROPIC_API_KEY:-}" ]; then
    echo "ANTHROPIC_API_KEY not set" >&2
    return 2
  fi
  echo "▶ matrix LLM review (6 (URL × identity) combos × Alice + Bob + Carol)"
  npx --yes promptfoo@latest eval -c promptfooconfig.matrix.review.yaml --no-cache "$@"
}

run_free_review() {
  cd "$UX_DIR"
  if ! [ -s "$VARS_DIR/alice_share.txt" ]; then
    echo "no matrix vars — run \`bin/promptfoo-ux.sh setup-matrix\` first" >&2
    return 2
  fi
  if [ -z "${ANTHROPIC_API_KEY:-}" ]; then
    echo "ANTHROPIC_API_KEY not set" >&2
    return 2
  fi
  echo "▶ free-form UX review (6 (URL × identity) combos × open judgment)"
  local out="/tmp/mantia-ux-free.json"
  npx --yes promptfoo@latest eval -c promptfooconfig.freereview.yaml --output "$out" --no-cache "$@"
  echo
  echo "▶ extracting findings → promptfoo/ux/last-free-review.md"
  python3 "$PROJECT_DIR/bin/ux-format-free-review.py" "$out" > "$UX_DIR/last-free-review.md"
  echo "  done. Open with:"
  echo "    less $UX_DIR/last-free-review.md"
}

run_expert_review() {
  cd "$UX_DIR"
  if ! [ -s "$VARS_DIR/alice_share.txt" ]; then
    echo "no matrix vars — run \`bin/promptfoo-ux.sh setup-matrix\` first" >&2
    return 2
  fi
  if [ -z "${ANTHROPIC_API_KEY:-}" ]; then
    echo "ANTHROPIC_API_KEY not set" >&2
    return 2
  fi
  echo "▶ senior UX expert audit (Nielsen heuristics + mobile + conversion)"
  local out="/tmp/mantia-ux-expert.json"
  npx --yes promptfoo@latest eval -c promptfooconfig.expertreview.yaml --output "$out" --no-cache "$@"
  echo
  echo "▶ extracting findings → promptfoo/ux/last-expert-review.md"
  python3 "$PROJECT_DIR/bin/ux-format-expert-review.py" "$out" > "$UX_DIR/last-expert-review.md"
  echo "  done. Open with:"
  echo "    less $UX_DIR/last-expert-review.md"
}

view_results() {
  cd "$UX_DIR"
  npx --yes promptfoo@latest view
}

run_matrix() {
  cd "$UX_DIR"
  if ! [ -s "$VARS_DIR/alice_share.txt" ]; then
    echo "no matrix vars on disk — run \`bin/promptfoo-ux.sh setup-matrix\` first" >&2
    return 2
  fi
  echo "▶ evaluating UX matrix (3 users × 2 pencas × mixed states)"
  npx --yes promptfoo@latest eval -c promptfooconfig.matrix.yaml --no-cache "$@"
}

run_interactive() {
  cd "$UX_DIR"
  if ! [ -s "$VARS_DIR/alice_share.txt" ]; then
    echo "no matrix vars on disk — run \`bin/promptfoo-ux.sh setup-matrix\` first" >&2
    return 2
  fi
  if [ ! -d node_modules/playwright ]; then
    echo "▶ installing playwright (first run)"
    npm install --save playwright >/dev/null 2>&1
    npx playwright install chromium >/dev/null 2>&1
  fi
  echo "▶ driving end-to-end interactive scenarios via headless Chrome"
  node interactive.mjs "$@"
}

case "$cmd" in
  setup)
    setup_state setup-canonical-user.php
    ;;
  setup-matrix)
    setup_state setup-matrix.php
    ;;
  run)
    shift
    run_eval "$@"
    ;;
  matrix)
    shift
    run_matrix "$@"
    ;;
  interactive)
    shift
    run_interactive "$@"
    ;;
  review)
    shift
    run_review "$@"
    ;;
  review-matrix)
    shift
    run_review_matrix "$@"
    ;;
  free-review)
    shift
    run_free_review "$@"
    ;;
  expert-review)
    shift
    run_expert_review "$@"
    ;;
  view)
    view_results
    ;;
  all)
    setup_state setup-canonical-user.php && run_eval
    ;;
  all-matrix)
    setup_state setup-matrix.php && run_matrix
    ;;
  full)
    setup_state setup-canonical-user.php && run_eval && run_review
    ;;
  *)
    echo "usage: $0 {setup|setup-matrix|run|matrix|interactive|review|view|all|all-matrix|full}" >&2
    echo "  setup          canonical 1-user fixture (Alice)" >&2
    echo "  setup-matrix   multi-user fixture (Alice + Bob + Carol × 2 pencas)" >&2
    echo "  run            deterministic asserts on canonical fixture (fast, free)" >&2
    echo "  matrix         deterministic asserts on matrix fixture (multi-user surface)" >&2
    echo "  interactive    end-to-end Playwright flows (click, navigate, snapshot)" >&2
    echo "  review         LLM-rubric per-page review (slow, costs tokens)" >&2
    echo "  view           open the result viewer" >&2
    echo "  all            setup + run" >&2
    echo "  all-matrix     setup-matrix + matrix" >&2
    echo "  full           setup + run + review" >&2
    exit 2
    ;;
esac
