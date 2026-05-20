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

## What it does

Two layers, both optional:

1. **Signed URL primitive** — `sign_link(payload, path)` /
   `verify_link(token)`. Consumer-defined opaque payload, library
   handles HMAC, expiry, path-binding, single-use enforcement.

2. **User resolver** — `resolve_or_create(phone, name)` returns a
   WP_User keyed by phone, creating one with the configured role +
   placeholder email if it doesn't exist yet. Consumers without a
   canonical identity model get find-or-create + lazy-on-click for
   free; consumers with one (a CPT, custom table) hook the redemption
   action and call `login_as()` themselves.

Plus: a dedicated WP role (default `whatsapp_user`) with password login
+ wp-admin blocked, so users entering via magic link can't escape into
the dashboard.

### Two ways to use it

**Simple consumer (no existing identity model):**
```php
add_filter( 'wa_identity_bridge_role_slug', fn () => 'bot_user' );
add_filter( 'wa_identity_bridge_path_whitelist', fn () => array( '/app/' ) );
WA_Identity_Bridge::boot();

// Bot sends a link — payload includes 'phone' so the default redemption
// handler will find-or-create + log in automatically on click:
$url = WA_Identity_Bridge::sign_link(
    array( 'phone' => $phone, 'name' => $name ),
    '/app/dashboard/'
);
```

**Consumer with existing identity:**
```php
WA_Identity_Bridge::boot();

// Attach your own redemption handler at default priority so it wins
// over the library's auto-resolve fallback:
add_action( 'wa_identity_bridge_redemption', function ( $payload, $path ) {
    $phone = (string) ( $payload['phone'] ?? '' );
    $user  = my_plugin_find_or_create_wp_user_by_phone( $phone );
    if ( $user ) {
        WA_Identity_Bridge::login_as( $user->ID );
    }
}, 10, 2 );
```

### Why the resolver is optional

Most managed WP hosts (wpcom Atomic among them) reject inserts with
non-public TLDs in `user_email` — `.local`, `.invalid`, etc. silently
fail with "Not enough data to create this user". The library defaults
the placeholder domain to `wa.<your-site-host>` to inherit a valid TLD;
override via `wa_identity_bridge_email_domain` if your host is stricter.

Eagerly materialising a WP_User on every WhatsApp inbound is fine for
most workloads, but if you have bursty unauthenticated traffic and care
about every byte of wp_users table, leave the user resolver off and
keep your canonical identity in a CPT or custom table.

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

// Optional user resolver — use either at chat time (eager) or let the
// library's default redemption handler call it lazily on click:
$user = WA_Identity_Bridge::resolve_or_create( $phone, $name );
$user = WA_Identity_Bridge::find_by_phone( $phone ); // never creates
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
| `wa_identity_bridge_user_login_prefix` | `''` | Prefix prepended to phone before storing as `user_login` (use to namespace if multiple consumers share an install). |
| `wa_identity_bridge_email_domain` | `wa.<site-host>` | Domain for the placeholder `user_email`. Avoid non-public TLDs (`.local`, `.invalid`) — they're rejected by Atomic-platform-style filters. |

## Actions

| Action | Args | Purpose |
|---|---|---|
| `wa_identity_bridge_redemption` | `$payload, $path` | Fires on every valid token redemption. Consumer hooks at priority ≤ 999 to pre-empt the default auto-resolve-and-login behaviour. |
| `wa_identity_bridge_logged_in` | `$user_id` | Fires after `login_as()` succeeds. Useful for audit logs. |
| `wa_identity_bridge_user_created` | `$user_id, $phone_e164, $display_name` | Fires once per user, immediately after a successful `wp_insert_user` from the resolver. Use to seed domain-specific state. |

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
