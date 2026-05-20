# WA_Identity_Bridge

Signed magic-link primitives for WordPress, designed to let plugins
offer a WhatsApp-as-auth flow without rebuilding the crypto + lockout
pieces. Pairs naturally with `openclawp` (WhatsApp Cloud API ingress) and
any consumer that wants chat-to-web continuity.

## Status

Internal library used by Mantia. Lives in-tree but is structurally
self-contained — extractable to its own repo with `git subtree split
--prefix=includes/wa-identity-bridge` whenever a second consumer
appears.

## What it does (and what it doesn't)

It **provides**:
- Signed, expiring URLs the bot can ship in chat messages.
- A redemption endpoint that verifies the signature, fires an action
  hook for the consumer to log the user in, and redirects cleanly.
- A dedicated WP role (default `whatsapp_user`) with password login +
  wp-admin blocked, so users entering via magic link can't escape into
  the dashboard.

It **does not**:
- Manage your canonical identity model. Most managed WP hosts (wpcom
  Atomic among them) rate-limit `wp_insert_user`. Eagerly materialising
  a WP_User for every WhatsApp inbound burns that budget on traffic
  that may never visit the web. Keep your canonical user in a CPT or
  custom table; lazily promote to a WP_User on the first redemption.

## Public API

```php
WA_Identity_Bridge::boot();
// Idempotent. Call from your plugin's bootstrap.

$url = WA_Identity_Bridge::sign_link(
    [ 'phone' => '598...', 'name' => 'Tincho' ],  // payload (opaque)
    '/penca/me/',                                   // path to redirect to
    [ 'ttl' => DAY_IN_SECONDS, 'single_use' => false ]
);
// → 'https://site/wa-auth/?wa_auth_t=<token>&wa_auth_go=%2Fpenca%2Fme%2F'

$payload = WA_Identity_Bridge::verify_link( $token, $expected_path );
// → array (your payload) on success, WP_Error on failure. Callers
//   should treat all errors as "expired or invalid" when surfacing
//   to end users; do not leak the specific code.

WA_Identity_Bridge::login_as( $user_id );
// wp_set_current_user + wp_set_auth_cookie + fires
// 'wa_identity_bridge_logged_in' for audit hooks.
```

## The redemption hook

The library's endpoint validates the token + path and then fires:

```php
do_action( 'wa_identity_bridge_redemption', array $payload, string $path );
```

Consumers attach to this and do their identity resolution + login:

```php
add_action( 'wa_identity_bridge_redemption', function ( $payload, $path ) {
    $phone = (string) ( $payload['phone'] ?? '' );
    $name  = (string) ( $payload['name'] ?? '' );
    if ( '' === $phone ) {
        return;
    }
    // Consumer-owned: find an existing wp_user by phone meta, or create one.
    $user = my_plugin_find_or_create_wp_user( $phone, $name );
    if ( $user instanceof WP_User ) {
        WA_Identity_Bridge::login_as( $user->ID );
    }
}, 10, 2 );
```

After the action chain finishes the library redirects to `$path` with a
302 — so any layer that fails to log the user in still lands the
visitor on the destination (which may be a public-by-token page).

## Configuration filters

All optional; defaults are sane for single-consumer installs.

| Filter | Default | Purpose |
|---|---|---|
| `wa_identity_bridge_role_slug` | `'whatsapp_user'` | Slug for the registered role. |
| `wa_identity_bridge_role_caps` | `['read' => true]` | Caps map for `add_role`. |
| `wa_identity_bridge_endpoint_path` | `'wa-auth'` | URL path for the auth endpoint. Slashes allowed (`'penca/auth'`). |
| `wa_identity_bridge_path_whitelist` | `[]` (permissive) | Leading-slash path prefixes that `?go=` is allowed to redirect to. **Strongly recommended.** |
| `wa_identity_bridge_default_ttl` | `DAY_IN_SECONDS` | TTL for `sign_link()` calls without `ttl`. |
| `wa_identity_bridge_default_single_use` | `false` | Single-use for `sign_link()` calls without `single_use`. |
| `wa_identity_bridge_block_wp_login` | `true` | Block password login + wp-admin for the role. |
| `wa_identity_bridge_expired_redirect_url` | `home_url('/')` | Where to send users whose token failed verification. |

## Actions

| Action | Args | Purpose |
|---|---|---|
| `wa_identity_bridge_redemption` | `$payload, $path` | Fires on every valid token redemption. Consumer hooks here for identity resolution + `login_as()`. |
| `wa_identity_bridge_logged_in` | `$user_id` | Fires after `login_as()` succeeds. Useful for audit logs. |

## Token format

```
payload_b64 . '.' . hmac_sha256(payload_b64, wp_salt('auth') . '|wa_identity_bridge')
envelope = { d: <your payload>, exp: <unix>, nonce: <hex>, su: 0|1, path: <path> }
```

The path is part of the signed envelope, so a valid token cannot be
reused against a different `?go=` destination.

## Security model

- **Phone-at-WhatsApp-API is the trust anchor.** WhatsApp's Cloud API
  authenticates the sender at the carrier level; consumers trust that
  and stash the phone in the signed payload.
- **Path whitelist** prevents `?go=` open-redirect. Configure one.
- **Single-use** is per-link, not global — use it for destructive or
  one-shot actions (account deletion, password reset). For nav links,
  multi-use is friendlier (a user reopening the same chat can click the
  same link twice and it just works).
- **wp-admin is blocked** for the role — the only sanctioned entry path
  is the magic link.

## Consumer example (Mantia)

```php
add_filter( 'wa_identity_bridge_role_slug', fn () => 'mantia_player' );
add_filter( 'wa_identity_bridge_endpoint_path', fn () => 'penca/auth' );
add_filter( 'wa_identity_bridge_path_whitelist', fn () => array( '/penca/' ) );

WA_Identity_Bridge::boot();

add_action( 'wa_identity_bridge_redemption', function ( $payload, $path ) {
    $phone = (string) ( $payload['phone'] ?? '' );
    $name  = (string) ( $payload['name'] ?? '' );
    if ( '' === $phone ) {
        return;
    }
    $user = Mantia_Identity::wp_user_for_phone( $phone, $name );
    if ( $user instanceof WP_User ) {
        WA_Identity_Bridge::login_as( $user->ID );
    }
}, 10, 2 );

// When sending a link in chat:
$url = WA_Identity_Bridge::sign_link(
    array( 'phone' => $phone, 'name' => $name ),
    '/penca/me/'
);
```

## Tests

```bash
ssh prod "cd htdocs && wp eval-file \
  wp-content/plugins/mantia/includes/wa-identity-bridge/tests/test-bridge.php"
```

Covers: sign + verify round-trip, signature tampering, path tampering,
single-use enforcement, TTL, path whitelist, role registration.

## Extraction (when ready)

```bash
git subtree split --prefix=includes/wa-identity-bridge -b wa-identity-bridge
# push the branch to a fresh repo, add a plugin.php bootstrap stub,
# install in consumers via composer or as a must-use plugin.
```

The library has zero references outside its directory.
