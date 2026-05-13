#!/usr/bin/env bash
# Run Mantia E2E tests. Target = local wp-env OR remote SSH.
#
#   bin/e2e.sh                              # all scenarios, default target
#   bin/e2e.sh penca-lifecycle              # one scenario
#   MANTIA_TARGET=local bin/e2e.sh          # local Docker wp-env (CI default)
#   MANTIA_TARGET=ssh MANTIA_SSH=user@host bin/e2e.sh   # remote WP install
#
# Local mode requires Docker + Node and runs `npx wp-env`. Remote mode
# requires SSH access + rsync; you must pass MANTIA_SSH explicitly. Both
# paths end up invoking `wp eval-file` on the right WP install.
set -euo pipefail

PROJECT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"
cd "$PROJECT_DIR"

# Default target: if .wp-env.json exists and Docker is reachable, prefer local.
# Otherwise fall back to SSH against mantia3.
if [[ -z "${MANTIA_TARGET:-}" ]]; then
  if [[ -n "${MANTIA_SSH:-}" ]]; then
    MANTIA_TARGET=ssh
  elif command -v docker >/dev/null && docker info >/dev/null 2>&1 && [[ -f .wp-env.json ]]; then
    MANTIA_TARGET=local
  else
    echo "No target detected. Set MANTIA_TARGET=local (Docker + wp-env) or MANTIA_TARGET=ssh MANTIA_SSH=user@host." >&2
    exit 2
  fi
fi

# Pick scenarios — args win, else everything under tests/e2e/.
SCENARIOS=("$@")
if [[ ${#SCENARIOS[@]} -eq 0 ]]; then
  SCENARIOS=()
  while IFS= read -r f; do
    SCENARIOS+=("$(basename "$f" .php)")
  done < <(find tests/e2e -maxdepth 1 -name '*.php' | sort)
fi

run_scenario() {
  local scenario="$1"
  echo ""
  echo "▶ scenario: ${scenario}  (target=${MANTIA_TARGET})"

  case "$MANTIA_TARGET" in
    local)
      # Forward MANTIA_E2E_BASE_URL into the CLI container so the lib's
      # HTTP helpers can reach the WP service over the docker network.
      local env_flags=()
      if [[ -n "${MANTIA_E2E_BASE_URL:-}" ]]; then
        env_flags+=( -e "MANTIA_E2E_BASE_URL=${MANTIA_E2E_BASE_URL}" )
      fi
      npx wp-env run "${env_flags[@]}" cli --env-cwd=/var/www/html/wp-content/plugins/mantia \
        wp eval-file "tests/e2e/${scenario}.php"
      ;;
    ssh)
      if [[ -z "${MANTIA_SSH:-}" ]]; then
        echo "MANTIA_TARGET=ssh requires MANTIA_SSH=user@host" >&2
        return 1
      fi
      local ssh_host="${MANTIA_SSH}"
      local plugin_path="${MANTIA_REMOTE_PATH:-htdocs/wp-content/plugins/mantia}"
      # Sync tests/ and bin/ as DIRECTORIES (no trailing slash on sources)
      # so they land at plugin_path/tests and plugin_path/bin, not flattened.
      rsync -az -e "ssh -o ConnectTimeout=15" \
        "${PROJECT_DIR}/tests" "${PROJECT_DIR}/bin" \
        "${ssh_host}:${plugin_path}/"
      ssh -o ConnectTimeout=15 "${ssh_host}" \
        "cd htdocs && wp cache flush >/dev/null && wp eval-file wp-content/plugins/mantia/tests/e2e/${scenario}.php"
      ;;
    *)
      echo "Unknown MANTIA_TARGET=${MANTIA_TARGET} (expected: local|ssh)" >&2
      exit 2
      ;;
  esac
}

FAILED=()
for s in "${SCENARIOS[@]}"; do
  if ! run_scenario "$s"; then
    FAILED+=("$s")
  fi
done

if [[ ${#FAILED[@]} -gt 0 ]]; then
  echo ""
  echo "❌ Failed scenarios: ${FAILED[*]}" >&2
  exit 1
fi
echo ""
echo "✅ All ${#SCENARIOS[@]} scenario(s) passed"
