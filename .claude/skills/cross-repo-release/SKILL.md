---
name: cross-repo-release
description: Audit a wa-identity-bridge API change for consumer breakage across Mantia and wp-carpeta before tagging a bridge release. Greps each consumer for usage of changed classes, methods, and filters; lints touched files; reports a punch list. Both invocable — Claude can run it during review, or you can invoke directly when planning a bridge bump.
---

# /cross-repo-release

Pre-release sanity check for `wa-identity-bridge` API changes. The bridge is now a published GPL plugin with two known consumers (Mantia, wp-carpeta) and zero formal versioning between them — this skill is the warning bell when a "small" bridge refactor would silently break a consumer.

## When to use

- About to bump the bridge to a new minor version (`0.3.x → 0.4.0`).
- Renamed a filter, changed a method signature, or shifted token format inside the bridge.
- Added a required parameter to `sign_link()` / `verify_link()`.
- Removed or restructured a public class (`WA_Identity_Bridge_*`).

## What it checks

For every consumer (`~/dev/a8c/mantia`, `~/dev/a8c/wp-carpeta`):

1. **Filter call sites.** Greps for `apply_filters` and `add_filter` whose first arg starts with `wa_identity_bridge_`. Cross-references against the filters the bridge itself declares (via `apply_filters` in `wa-identity-bridge/includes/`). Any consumer-side filter that no longer exists in the bridge → flagged.
2. **Class usage.** Greps for `WA_Identity_Bridge` and `WA_Identity_Bridge_*` references. Confirms each referenced class still exists in the bridge sources.
3. **Method signatures.** For each `WA_Identity_Bridge::method()` call in the consumer, parses the bridge's method declaration and warns if the consumer is passing fewer args than the new signature requires.
4. **Constants.** Greps for `WA_IDENTITY_BRIDGE_*` constants used by consumers; confirms they're still defined.
5. **`Requires Plugins:` lint.** Confirms both consumers declare `wa-identity-bridge` in their plugin header so WP surfaces missing-dependency notices instead of silent fatals.
6. **Lints.** Runs `php -l` on every consumer file that contains bridge references. Surfaces only parse errors.

## What it intentionally doesn't do

- Doesn't run the consumer e2e suites (slow + flaky to start cold). Run `/deploy-mantia` separately for that.
- Doesn't check token format byte-compatibility — if you changed the token format, that's a major bump and you already know what you're doing.
- Doesn't touch git. Read-only across all three repos.

## Output

A grouped punch list, one section per consumer:

```
== mantia ==
  ✓ filters: 4/4 still exist
  ✗ classes: WA_Identity_Bridge_Lockout referenced but not declared in bridge
      → includes/class-mantia-bootstrap.php:42
  ✓ method signatures: ok
  ✓ Requires Plugins: declared
  ✓ lints: clean

== wp-carpeta ==
  ✓ filters: 4/4 still exist
  ✓ classes: ok
  ✓ method signatures: ok
  ✗ Requires Plugins: missing 'wa-identity-bridge'
  ✓ lints: clean
```

Exit 0 if all consumers pass, exit 1 otherwise.

## Shell

```bash
set -uo pipefail

BRIDGE="${HOME}/dev/a8c/wa-identity-bridge"
CONSUMERS=("${HOME}/dev/a8c/mantia" "${HOME}/dev/a8c/wp-carpeta")
EXIT=0

if [[ ! -d "$BRIDGE/includes" ]]; then
  echo "❌ Bridge sources not found at $BRIDGE" >&2
  exit 2
fi

# 1. Build the set of filters the bridge currently declares.
mapfile -t BRIDGE_FILTERS < <(
  grep -rhoE "apply_filters\(\s*'wa_identity_bridge_[a-z_]+'" "$BRIDGE/includes" \
    | sed -E "s/.*'(wa_identity_bridge_[a-z_]+)'.*/\1/" | sort -u
)

# 2. Build the set of public classes.
mapfile -t BRIDGE_CLASSES < <(
  grep -rhoE "class WA_Identity_Bridge(_[A-Z][a-zA-Z_]+)?" "$BRIDGE/includes" \
    | awk '{print $2}' | sort -u
)

for CONSUMER in "${CONSUMERS[@]}"; do
  NAME=$(basename "$CONSUMER")
  echo "== $NAME =="

  if [[ ! -d "$CONSUMER" ]]; then
    echo "  ⚠️  not found at $CONSUMER — skipped"
    continue
  fi

  # Filters
  mapfile -t USED_FILTERS < <(
    grep -rhoE "(add_filter|apply_filters)\(\s*'wa_identity_bridge_[a-z_]+'" "$CONSUMER" 2>/dev/null \
      | sed -E "s/.*'(wa_identity_bridge_[a-z_]+)'.*/\1/" | sort -u
  )
  MISSING_F=0
  for F in "${USED_FILTERS[@]}"; do
    if ! printf '%s\n' "${BRIDGE_FILTERS[@]}" | grep -qx "$F"; then
      [[ $MISSING_F -eq 0 ]] && echo "  ✗ filters:" && MISSING_F=1
      LOC=$(grep -rn "'$F'" "$CONSUMER" --include='*.php' | head -1)
      echo "      $F not in bridge → $LOC"
      EXIT=1
    fi
  done
  [[ $MISSING_F -eq 0 ]] && echo "  ✓ filters: ${#USED_FILTERS[@]}/${#USED_FILTERS[@]} still exist"

  # Classes
  MISSING_C=0
  mapfile -t USED_CLASSES < <(
    grep -rhoE "WA_Identity_Bridge(_[A-Z][a-zA-Z_]+)?" "$CONSUMER" --include='*.php' 2>/dev/null \
      | sort -u
  )
  for C in "${USED_CLASSES[@]}"; do
    if ! printf '%s\n' "${BRIDGE_CLASSES[@]}" | grep -qx "$C"; then
      [[ $MISSING_C -eq 0 ]] && echo "  ✗ classes:" && MISSING_C=1
      LOC=$(grep -rn "$C" "$CONSUMER" --include='*.php' | head -1)
      echo "      $C not declared in bridge → $LOC"
      EXIT=1
    fi
  done
  [[ $MISSING_C -eq 0 ]] && echo "  ✓ classes: ok"

  # Requires Plugins
  ENTRY=$(find "$CONSUMER" -maxdepth 2 -name '*.php' -exec grep -l '^ \* Plugin Name:' {} + 2>/dev/null | head -1)
  if [[ -n "$ENTRY" ]] && grep -q '^ \* Requires Plugins:.*wa-identity-bridge' "$ENTRY"; then
    echo "  ✓ Requires Plugins: declared"
  else
    echo "  ✗ Requires Plugins: missing 'wa-identity-bridge' in $(basename "$ENTRY")"
    EXIT=1
  fi

  # Lints — only files that actually reference the bridge
  PARSE_ERR=0
  mapfile -t TOUCHED < <(
    grep -rl 'WA_Identity_Bridge\|wa_identity_bridge_' "$CONSUMER" --include='*.php' 2>/dev/null
  )
  for f in "${TOUCHED[@]}"; do
    OUT=$(php -l "$f" 2>&1)
    if echo "$OUT" | grep -qi 'parse error'; then
      [[ $PARSE_ERR -eq 0 ]] && echo "  ✗ lints:" && PARSE_ERR=1
      echo "      $f:" $(echo "$OUT" | grep -i 'parse error' | head -1)
      EXIT=1
    fi
  done
  [[ $PARSE_ERR -eq 0 ]] && echo "  ✓ lints: clean"

  echo
done

exit $EXIT
```

## Follow-up

If anything failed:
- Filter missing → either restore the old filter name in the bridge (back-compat shim) or update the consumer to use the new name.
- Class missing → same calculus.
- `Requires Plugins:` missing → trivial fix in the consumer's main plugin file.
- Parse error → almost certainly a half-finished edit. Open the file and finish it.

Run again until clean before tagging the bridge release.
