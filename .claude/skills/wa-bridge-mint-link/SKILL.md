---
name: wa-bridge-mint-link
description: Mint a WA Identity Bridge signed magic-link for testing and immediately verify it round-trips. Targets the local wp-env install by default; pass "mantia3" as the first arg to hit production. User-only — it runs `wp eval` against a live install.
disable-model-invocation: true
---

# /wa-bridge-mint-link

One-liner replacement for the 12-line `wp eval` heredoc you keep retyping when you want to confirm the bridge is actually working.

## What it does

1. Builds a Mantia-shaped payload (`phone` + `name`) — defaults are easy to remember but every field is overridable.
2. Calls `WA_Identity_Bridge::sign_link()` to mint the URL.
3. Extracts the `wa_auth_t` query var and calls `verify_link()` to confirm the token validates against the path.
4. Prints: URL length, verified payload, role existence check, bridge version, file the class was loaded from (so you can see whether the standalone plugin or an in-tree copy won).

Output is intentionally short — 6 lines, paste-able into a Slack DM when Matías asks "is it working?"

## When to use

- Right after deploying a change to `wa-identity-bridge` (local or mantia3) to confirm the bridge still mints + verifies.
- After upgrading Mantia past v10 to confirm the standalone-plugin handoff actually happened (`loaded-from:` line will show the standalone path, not `mantia/includes/wa-identity-bridge/...`).
- When debugging a "link expired" complaint from a real user — re-mint with the same TTL and see if the verifier accepts it.

## Arguments

```
/wa-bridge-mint-link [target] [phone] [path] [ttl]
```

| Arg | Default | Notes |
| --- | --- | --- |
| `target` | `local` | `local` → wp-env via `npx -y @wordpress/env run cli`. `mantia3` → SSH to `mantia3.wordpress.com@ssh.wp.com`. |
| `phone` | `+59899111222` | E.164. UY default so it exercises the penca vocab path. |
| `path` | `/pronostico/` | Must match a whitelisted path (`wa_identity_bridge_path_whitelist`). |
| `ttl` | `300` | Seconds. Short by default so an accidentally-leaked test URL dies fast. |

## Shell

```bash
set -euo pipefail
TARGET="${1:-local}"
PHONE="${2:-+59899111222}"
PATH_ARG="${3:-/pronostico/}"
TTL="${4:-300}"

PHP=$(cat <<'PHP'
$phone = $argv[1] ?? '+59899111222';
$path  = $argv[2] ?? '/pronostico/';
$ttl   = (int) ( $argv[3] ?? 300 );

if ( ! class_exists( 'WA_Identity_Bridge' ) ) {
    echo "❌ WA_Identity_Bridge class not loaded — is the standalone plugin (or Mantia in-tree copy) active?\n";
    exit( 1 );
}

$url = WA_Identity_Bridge::sign_link(
    array( 'phone' => $phone, 'name' => 'BridgeTest' ),
    $path,
    array( 'ttl' => $ttl )
);
echo "signed: " . ( $url ? "yes (len=" . strlen( $url ) . ")" : "no" ) . "\n";
if ( ! $url ) { exit( 1 ); }

preg_match( '/wa_auth_t=([^&]+)/', $url, $m );
$verified = WA_Identity_Bridge::verify_link( urldecode( $m[1] ), $path );
echo is_wp_error( $verified )
    ? "verify-FAILED: " . $verified->get_error_message() . "\n"
    : "verify-OK: phone=" . $verified['phone'] . "\n";

echo "role-exists: " . ( get_role( WA_Identity_Bridge::role_slug() ) ? "yes (" . WA_Identity_Bridge::role_slug() . ")" : "no" ) . "\n";
echo "bridge-version: " . WA_Identity_Bridge::VERSION . "\n";
echo "loaded-from: " . ( new ReflectionClass( 'WA_Identity_Bridge' ) )->getFileName() . "\n";
echo "url: " . $url . "\n";
PHP
)

case "$TARGET" in
  local)
    # Pipe the PHP through wp-env's cli container; argv is passed via --
    cd "$(git rev-parse --show-toplevel)"
    echo "$PHP" | npx -y @wordpress/env run cli "wp eval-file -" "$PHONE" "$PATH_ARG" "$TTL"
    ;;
  mantia3)
    # Stream the PHP over SSH; wp eval-file - reads from stdin
    echo "$PHP" | ssh -o ConnectTimeout=15 mantia3.wordpress.com@ssh.wp.com \
      "cd /srv/htdocs && wp eval-file - $(printf %q "$PHONE") $(printf %q "$PATH_ARG") $TTL"
    ;;
  *)
    echo "Unknown target: $TARGET (expected: local | mantia3)" >&2
    exit 2
    ;;
esac
```

## Failure modes worth knowing

- **`signed: no`** → the path failed the whitelist check (`wa_identity_bridge_path_whitelist` filter). Mantia whitelists `/pronostico/`; Carpeta whitelists `/carpeta/`. If you're testing a different path, add it via filter first.
- **`verify-FAILED: token_expired`** → TTL too short for the round-trip. Bump `ttl` or check the server clock.
- **`loaded-from:` shows `mantia/includes/wa-identity-bridge/...` on mantia3** → the in-tree copy is still on disk. Means the v10 Mantia release didn't deploy cleanly. Re-run the rsync deploy with `--delete`.
