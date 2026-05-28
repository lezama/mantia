#!/usr/bin/env bash
# LLM-rubric review of the e2e bot transcript.
#
# Pipeline:
#   1. Run bin/e2e.sh stakeholder-sim-onboarding against mantia3 (or local).
#      The harness dumps the full transcript to tests/qa-output/ on the
#      target host.
#   2. Rsync that transcript back to the local working tree.
#   3. Run promptfoo with promptfoo/transcript/promptfooconfig.yaml —
#      each test loads the transcript via the JS provider and grades it
#      through a stakeholder lens (joiner / creator / voice / say-do).
#   4. Extract findings into promptfoo/transcript/last-review.md.
#
# Subcommands:
#   refresh  re-run the e2e + rsync the transcript back
#   review   run promptfoo against whatever transcript is already cached
#   all      refresh + review (default)
#
# Env:
#   ANTHROPIC_API_KEY   required for the LLM grader
#   MANTIA_SSH          target SSH host (default mantia3.wordpress.com@ssh.wp.com)

set -uo pipefail

SSH_HOST="${MANTIA_SSH:-mantia3.wordpress.com@ssh.wp.com}"
PROJECT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"
PLUGIN_PATH="wp-content/plugins/mantia"
TRANSCRIPT_REMOTE="/srv/htdocs/${PLUGIN_PATH}/tests/qa-output/stakeholder-sim-onboarding.txt"
TRANSCRIPT_LOCAL="${PROJECT_DIR}/tests/qa-output/stakeholder-sim-onboarding.txt"
CONFIG_DIR="${PROJECT_DIR}/promptfoo/transcript"

# Promptfoo needs Node ^20.20 or >=22.22 — same nvm dance as the UX wrapper.
if [ -s "$HOME/.nvm/nvm.sh" ]; then
  # shellcheck disable=SC1091
  . "$HOME/.nvm/nvm.sh"
  if nvm version 22.22.0 >/dev/null 2>&1; then
    nvm use 22.22.0 >/dev/null 2>&1 || true
  elif nvm version 20.20.0 >/dev/null 2>&1; then
    nvm use 20.20.0 >/dev/null 2>&1 || true
  fi
fi

cmd="${1:-all}"

refresh_transcript() {
  echo "▶ regenerating transcript via bin/e2e.sh stakeholder-sim-onboarding (target=ssh)"
  MANTIA_TARGET=ssh MANTIA_SSH="$SSH_HOST" "${PROJECT_DIR}/bin/e2e.sh" stakeholder-sim-onboarding > /tmp/mantia-transcript-e2e.log 2>&1
  local e2e_rc=$?
  if [ "$e2e_rc" -ne 0 ]; then
    echo "  ✗ e2e failed (rc=$e2e_rc) — see /tmp/mantia-transcript-e2e.log"
    tail -20 /tmp/mantia-transcript-e2e.log
    return $e2e_rc
  fi
  echo "  · e2e ok"

  echo "▶ pulling transcript back from $SSH_HOST"
  rsync -az -e "ssh -o ConnectTimeout=15" \
    "${SSH_HOST}:${TRANSCRIPT_REMOTE}" \
    "${TRANSCRIPT_LOCAL}" > /dev/null
  if [ ! -s "$TRANSCRIPT_LOCAL" ]; then
    echo "  ✗ rsync returned empty/missing transcript at $TRANSCRIPT_LOCAL" >&2
    return 2
  fi
  local lines
  lines="$( wc -l < "$TRANSCRIPT_LOCAL" )"
  echo "  · local transcript refreshed ($(awk '{print int($1)}' <<<"$lines") lines)"
}

run_review() {
  if [ ! -s "$TRANSCRIPT_LOCAL" ]; then
    echo "✗ no transcript at $TRANSCRIPT_LOCAL — run \`bin/promptfoo-transcript.sh refresh\` first." >&2
    return 2
  fi
  if [ -z "${ANTHROPIC_API_KEY:-}" ]; then
    echo "✗ ANTHROPIC_API_KEY not set" >&2
    return 2
  fi

  cd "$CONFIG_DIR" || return 2
  local out="/tmp/mantia-transcript-review.json"
  echo "▶ grading transcript with LLM rubrics (4 scenes)"
  npx --yes promptfoo@latest eval -c promptfooconfig.yaml --output "$out" --no-cache "$@"
  local rc=$?

  echo
  echo "▶ extracting findings → promptfoo/transcript/last-review.md"
  python3 "${PROJECT_DIR}/bin/ux-format-transcript-review.py" "$out" > "${CONFIG_DIR}/last-review.md"
  echo "  done. Open with:"
  echo "    less ${CONFIG_DIR}/last-review.md"
  return $rc
}

case "$cmd" in
  refresh)
    refresh_transcript
    ;;
  review)
    shift
    run_review "$@"
    ;;
  all|"")
    # If the user typed `all` explicitly, drop it before forwarding the
    # rest of $@ to run_review. With no args, $# is already 0 and
    # `shift` would return non-zero, short-circuiting the `&&`.
    if [ "$#" -gt 0 ]; then shift; fi
    refresh_transcript && run_review "$@"
    ;;
  *)
    echo "usage: $0 {refresh|review|all}" >&2
    echo "  refresh  re-run e2e on mantia3 + pull transcript back" >&2
    echo "  review   grade the cached transcript with LLM rubrics" >&2
    echo "  all      refresh + review (default)" >&2
    exit 2
    ;;
esac
