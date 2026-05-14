# Troubleshooting

Failure modes we've hit and how to recover. Mostly Meta + WP environment
weirdness — pure plugin bugs are usually caught by `bin/e2e.sh`.

## "Bot stopped responding"

### Symptom

Users send messages, get no reply. The bot looks dead.

### Diagnose

```bash
tail -50 wp-content/debug.log | grep openclawp
```

What you're looking for:

- `chat_turn={"...":"...","success":true}` → LLM ran, the agent's reply
  was generated. If the next line is `whatsapp_send_failed status=401` →
  Meta rejected the outbound call.
- `whatsapp_send_failed status=401 body=OAuthException code=190` → **Token expired or revoked**. Regenerate per `docs/operations.md` → Access token rotation.
- `whatsapp_send_failed status=400` → Phone Number ID mismatch, or the
  number is in a state Meta won't let us send from. Check Meta dashboard.
- No `chat_turn` lines at all → openclawp never received the message.
  Webhook config drift; verify `hub.verify_token` matches, callback URL
  matches the live `/wp-json/openclawp/v1/whatsapp/webhook`.

### Common cause: token rotation cycles

Meta tokens generated via the "API Setup" dev console expire in 24h.
Replace with a **System User token** (never expires unless revoked) —
see `docs/operations.md`.

## "Webhook verification fails in Meta dashboard"

Meta hits the webhook URL with `?hub.mode=subscribe&hub.verify_token=X&hub.challenge=42abc`
and expects `42abc` echoed back as **plain text**, not JSON.

If verification fails:

- Check the verify token in Meta matches **wp-admin → openclaWP →
  WhatsApp → Webhook Verify Token** exactly (case-sensitive, no whitespace).
- Test the webhook URL manually:
  ```bash
  curl -i 'https://<your-site>/wp-json/openclawp/v1/whatsapp/webhook?hub.mode=subscribe&hub.verify_token=<your-token>&hub.challenge=42abc'
  ```
  Expect `HTTP/2 200` + body `42abc` (no quotes). If you get `"42abc"`
  with quotes, the openclawp PR #32 fix isn't deployed — pull latest
  `main`.

## "PHP fatal: php-ai-client/polyfills.php not found"

This was a real one. WordPress 7.0 ships `wordpress/php-ai-client` in
core, and openclawp used to vendor it as a Composer dep. The duplicate
namespace (`Psr\EventDispatcher\EventDispatcherInterface` prefixed by
Strauss vs unprefixed in core) caused a `TypeError` in `PromptBuilder`.

**Fix shipped**: openclawp's composer.json no longer declares the dep.
If you see this fatal, your openclawp install is stale — pull `main`
and run `composer install --no-dev` (or just `rsync` the latest source).

## "Admin notice: App Secret empty"

The HMAC verification short-circuits to `true` when `app_secret` is
empty, so the webhook accepts any POST. The admin notice fires across
every wp-admin page until you set the secret.

Get the secret from Meta dashboard → App Settings → Basic → **App
Secret** → Show → paste into wp-admin → openclaWP → WhatsApp → App Secret.

## "Pendientes shows 0 but I haven't predicted anything"

The user's pending list is computed by walking `upcoming_matches_for_competition` for every competition where the user has a penca. If the count is 0, one of these is wrong:

- All matches for those competitions are `status=finished` (Mundial demo
  matches get stuck this way after E2E test runs — fix below).
- All match `kickoff_gmt` timestamps are in the past.
- The user has no groups in any competition with future matches.

Reset the demo fixtures back to `scheduled`:

```bash
wp eval 'Mantia_Fixture_Seeder::seed();' # idempotent — overwrites status to "scheduled"
```

## "Studio Sync push reports 'sync in progress'"

This is a wp.com infra hiccup, not a Mantia issue. The content
**did** push (verify by SSH `ls htdocs/wp-content/plugins/mantia/`),
but the lock didn't release cleanly. Either wait 5-10 minutes or kick
the lock by re-pushing.

Filed upstream as Studio issue #3460.

## "Local SSH closes the connection immediately"

```
Connection closed by 192.0.96.181 port 22
```

Two causes:

- **No identity loaded.** Run `ssh-add ~/.ssh/id_ed25519` and enter your
  passphrase.
- **Rate limit cooldown.** After ~5 failed auth attempts wp.com SSH puts
  your IP in a 60-second cooldown. Wait, then retry.

The host pattern changed in mid-2026: use `<site>.wordpress.com@ssh.wp.com`,
NOT `<site>.wpcomstaging.com@ssh.wp.com`.

## "wp eval-file fails inside wp-env CLI: localhost:8889 unreachable"

`wp_remote_get( home_url() )` from the CLI container can't reach the
host-mapped port. The E2E lib detects the `mantia_e2e_base_url` option
and routes over `http://wordpress` (the docker service name) with the
canonical Host header set, so the redirect doesn't bounce.

In CI, this is set automatically. For local debugging:

```bash
wp-env run cli wp option set mantia_e2e_base_url http://wordpress
```

## "I broke production — how do I revert?"

The three substrate repos all keep tags + history. Quick paths:

```bash
# Roll openclawp back to a known-good commit:
cd /Users/miguel/dev/a8c/openclawp
git checkout <good-sha>
rsync -avz --delete ./ mantia3.wordpress.com@ssh.wp.com:htdocs/wp-content/plugins/openclawp/

# Same pattern for agents-api and mantia.
```

For DB rollbacks: wp.com keeps daily backups under the Dashboard. There
is no plugin-level rollback for predictions / leaderboard state.
