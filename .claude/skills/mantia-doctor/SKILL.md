---
name: mantia-doctor
description: Health check the live mantia3 install. Verifies plugins active, REST endpoint up, PWA endpoints reachable, fixture seeded, and runs an e2e baseline. Output is a green/red checklist. Safe to run anytime — read-only against the server.
---

# /mantia-doctor

Single-command "is mantia3 working?" status check. I run an ad-hoc version of this several times per session; this skill packages it so you (or Claude) can call it without re-typing the curl/SSH dance.

## What it checks

| Check | How |
|---|---|
| **agents-api active** | `wp plugin is-active agents-api` |
| **openclawp active** | `wp plugin is-active openclawp` |
| **mantia active** | `wp plugin is-active mantia` |
| **Mantia CPTs registered** | `wp eval "print_r(post_type_exists('mantia_match'));"` etc. |
| **Fixture seeded** | match count > 0 for `mantia_match` |
| **REST root reachable** | curl `https://mantia3.wpcomstaging.com/wp-json/` → 200 |
| **REST predictions endpoint** | curl `POST /wp-json/mantia/v1/predictions` with bad token → 400/404 |
| **PWA manifest** | curl `/manifest.json/` → 200 + `application/manifest+json` |
| **Service worker** | curl `/service-worker.js/` → 200 + `Service-Worker-Allowed: /` |
| **Icons** | curl `/icons/192.png/` and `/icons/512.png/` → both 200 + image/png |
| **E2E baseline** | `bin/e2e.sh penca-lifecycle` (single scenario, ~3s) |

Anything red gets reported with the response code / error message. Greens get a `✓` and move on.

## Shell

```bash
set -uo pipefail
SSH_HOST='mantia3.wordpress.com@ssh.wp.com'
KEY="${HOME}/.ssh/id_ed25519"
BASE='https://mantia3.wpcomstaging.com'
GREEN='\033[0;32m'; RED='\033[0;31m'; NC='\033[0m'
PASS=0; FAIL=0

ok()   { printf "${GREEN}✓${NC} %s\n" "$1"; PASS=$((PASS+1)); }
fail() { printf "${RED}✗${NC} %s\n" "$1"; FAIL=$((FAIL+1)); }

echo "▶ Plugin status (SSH)"
for plugin in agents-api openclawp mantia; do
  if ssh -i "$KEY" -o ConnectTimeout=10 "$SSH_HOST" "wp --skip-themes plugin is-active $plugin" >/dev/null 2>&1; then
    ok "$plugin active"
  else
    fail "$plugin inactive"
  fi
done

echo
echo "▶ CPTs + fixture (SSH eval)"
CPT_OK=$(ssh -i "$KEY" "$SSH_HOST" "wp --skip-themes eval 'echo (post_type_exists(\"mantia_match\") && post_type_exists(\"mantia_prediction\") && post_type_exists(\"mantia_group\") && post_type_exists(\"mantia_user\")) ? 1 : 0;'" 2>/dev/null || echo 0)
[[ "$CPT_OK" == "1" ]] && ok "CPTs registered" || fail "CPTs missing — Bootstrap::init likely failed"

MATCH_COUNT=$(ssh -i "$KEY" "$SSH_HOST" 'wp --skip-themes post list --post_type=mantia_match --format=count 2>/dev/null' || echo 0)
[[ "$MATCH_COUNT" -gt 0 ]] && ok "fixture seeded ($MATCH_COUNT matches)" || fail "no matches loaded"

echo
echo "▶ Web surfaces (curl)"

http_check() {
  local url="$1" expected="$2" label="$3" extra_grep="${4:-}"
  local resp; resp=$(curl -sI -L "$url" 2>&1) || { fail "$label — curl failed"; return; }
  local code; code=$(printf '%s\n' "$resp" | grep -m1 -i '^HTTP/' | awk '{print $2}')
  if [[ "$code" == "$expected" ]]; then
    if [[ -n "$extra_grep" ]] && ! printf '%s\n' "$resp" | grep -qi "$extra_grep"; then
      fail "$label — response missing '$extra_grep'"
    else
      ok "$label → $code"
    fi
  else
    fail "$label → $code (expected $expected)"
  fi
}

http_check "${BASE}/wp-json/"               "200" "REST root"
http_check "${BASE}/manifest.json/"         "200" "PWA manifest"   "application/manifest+json"
http_check "${BASE}/service-worker.js/"     "200" "PWA service worker" "service-worker-allowed: /"
http_check "${BASE}/icons/192.png/"         "200" "PWA icon 192"   "image/png"
http_check "${BASE}/icons/512.png/"         "200" "PWA icon 512"   "image/png"

# Predictions endpoint should reject bad tokens cleanly (404, not 500).
PRED_CODE=$(curl -s -o /dev/null -w '%{http_code}' -X POST \
  -H 'Content-Type: application/json' \
  -d '{"token":"0000000000000000000000aa","match_id":1,"home_score":0,"away_score":0}' \
  "${BASE}/wp-json/mantia/v1/predictions")
if [[ "$PRED_CODE" == "404" ]]; then
  ok "REST predictions rejects bad token cleanly"
else
  fail "REST predictions returned $PRED_CODE (expected 404)"
fi

echo
echo "▶ E2E baseline (penca-lifecycle, ~3s)"
if MANTIA_TARGET=ssh MANTIA_SSH="$SSH_HOST" bin/e2e.sh penca-lifecycle 2>&1 | tail -3 | grep -q '✅ PASS'; then
  ok "penca-lifecycle scenario green"
else
  fail "penca-lifecycle failing — investigate before deploys"
fi

echo
printf "%s checks passed, %s failed\n" "$PASS" "$FAIL"
[[ "$FAIL" -gt 0 ]] && exit 1 || exit 0
```

## When to run

- Start of a session, before making changes (especially after a substrate deploy)
- After `/deploy-mantia` to confirm nothing snapped
- Before tagging a release with `/bump-version`
- Whenever a user reports "the bot isn't replying"
