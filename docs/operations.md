# Operations

Day-to-day running of a Mantia install (current production: `mantia3.wpcomstaging.com`).

## Match results — how they're scored

A match becomes "scored" once it has `status=finished` + scores set. The
cron-driven workflow `mantia/resolve-matches` runs every 15 minutes and
processes any finished-but-unresolved match: it reads the result, scores
every prediction (5/3/1/0 per default rules), marks `resolved=1`, and
fires the `mantia_match_resolved` action so other workflows can react
(e.g. "Termina X 2 Y 1. Líder: @juan con 23 pts" to the group chat).

So the question is: how does a match get marked `finished`?

### Path A — Admin enters results in wp-admin (works today)

1. wp-admin → **Mantia Matches** → edit the match
2. Set Custom Fields:
   - `_mantia_status` → `finished`
   - `_mantia_home_score` → `2`
   - `_mantia_away_score` → `1`
3. Save. Within 15 minutes the resolver workflow picks it up.

Quickest path with wp-cli:

```bash
wp post meta update <match_id> _mantia_status finished
wp post meta update <match_id> _mantia_home_score 2
wp post meta update <match_id> _mantia_away_score 1
# Force-resolve immediately instead of waiting for cron:
wp eval 'Mantia_Abilities::resolve_match( array( "match_id" => <match_id> ) );'
```

### Path B — External results provider (hook ready, provider not built)

Wire any feed (api-football, ESPN, FIFA scrape) via the
`mantia_fetch_match_result` filter:

```php
add_filter( 'mantia_fetch_match_result', function ( $result, $match ) {
    // call provider, parse, return:
    return array(
        'home_score' => 2,
        'away_score' => 1,
        'status'     => 'finished',
        'source'     => 'api-football',
    );
}, 10, 2 );
```

Return `null` to fall back to admin-entered meta. Return `WP_Error` to
skip resolution for that match (predictions stay pending).

Pair it with a small "fetcher" workflow that runs every 30 min for
matches whose `kickoff` is in the last 3h — that closes the loop with
zero manual intervention.

### Path C — WhatsApp admin command (not built yet)

Conceptually: bot owner sends `resultado Liverpool 2 Chelsea 1` to the
bot, restricted to a configured owner phone. Useful for fast same-day
overrides without opening wp-admin. ~30 lines in the preflight if you
want it built.

## Fixture management

Each competition ships a JSON file under `tools/seed-<slug>.json`. The
seeder runs on plugin activation, and `Mantia_Fixture_Seeder::seed()`
is idempotent (matches keyed by `external_id`, upserted).

To add fixtures:

1. Drop a new `tools/seed-<slug>.json` with the same shape:
   ```json
   {
     "competition_id": "<slug>",
     "matches": [
       { "external_id": "uniq-1", "home_team": "...", "away_team": "...",
         "kickoff_gmt": "2026-06-11 19:00:00", "phase": "...",
         "status": "scheduled", "competition_id": "<slug>" }
     ]
   }
   ```
2. The seeder auto-discovers via `glob('tools/seed-*.json')`.
3. Trigger via:
   ```bash
   wp eval 'Mantia_Fixture_Seeder::seed();'
   ```

If the competition doesn't exist yet, create the CPT post first:

```bash
wp post create --post_type=mantia_competition --post_status=publish \
  --post_title="My Tournament" --post_name="my-tournament"
wp post meta add <id> _mantia_competition_emoji "🏆"
```

Then edit it in wp-admin → Mantia Competitions to add aliases (the
free-text WhatsApp hints that route users to that competition).

## Meta WhatsApp Cloud API

### Access token rotation

System User tokens never expire by default but can be revoked. If the
bot stops replying with `401 OAuthException code=190` in the debug log,
the token died:

1. https://business.facebook.com/settings/system-users → select your
   System User → **Generate new token**
2. App: your WhatsApp Business App
3. Token expiration: **Nunca**
4. Scopes: `whatsapp_business_messaging` + `whatsapp_business_management`
5. Copy the token (shown once)
6. Paste it into **wp-admin → openclaWP → WhatsApp → Permanent Access Token**

Or via wp-cli:

```bash
wp eval '
$s = get_option("openclawp_whatsapp_settings", array());
$s["access_token"] = "EAA...new-token...";
update_option("openclawp_whatsapp_settings", $s);
'
```

Verify with a Graph API ping:

```bash
wp eval '
$s = get_option("openclawp_whatsapp_settings");
$res = wp_remote_get(
  "https://graph.facebook.com/v25.0/" . $s["phone_number_id"],
  array( "headers" => array( "Authorization" => "Bearer " . $s["access_token"] ) )
);
echo wp_remote_retrieve_response_code( $res ) . "\n";
echo wp_remote_retrieve_body( $res );
'
```

200 + `verified_name` = healthy.

### App Secret (HMAC verification)

Without an App Secret set, openclaWP accepts any POST to the webhook URL
(useful for dev, foot-gun in production — anyone who finds the URL can
drive the agent). Set it once from Meta dashboard → App Settings → Basic
→ App Secret → paste into wp-admin → openclaWP → WhatsApp → App Secret.

An admin notice fires across wp-admin when this state is detected.

### Webhook configuration in Meta

- **Callback URL**: `https://<your-site>/wp-json/openclawp/v1/whatsapp/webhook`
- **Verify token**: matches the value in wp-admin → openclaWP → WhatsApp → Webhook Verify Token
- **Webhook fields**: subscribe to `messages` only

## Logs

Mantia uses openclaWP's structured log lines, written to
`wp-content/debug.log` (requires `WP_DEBUG_LOG=true`):

| Pattern | What it means |
|---|---|
| `[openclawp] event=turn_started payload={...}` | A WA message reached the agent runtime |
| `[openclawp] chat_turn={"...":"...","success":true}` | LLM turn completed (with token usage) |
| `[openclawp] event=tool_call` | The agent called a Mantia ability |
| `[openclawp] event=tool_result` | The ability returned |
| `[openclawp] whatsapp_send_failed status=4xx body={...}` | Outbound to Meta failed (usually expired token) |
| `[openclawp] whatsapp_interactive_send_failed` | Outbound button/list message failed |

Tail with:

```bash
wp eval 'echo WP_CONTENT_DIR;' # find debug.log path
tail -f wp-content/debug.log | grep openclawp
```

## Rate limits

Per-phone inbound limit defaults to **20 messages / 60 seconds**. Tune via:

```php
add_filter( 'mantia_rate_limit_max', fn() => 30 );
add_filter( 'mantia_rate_limit_window_seconds', fn() => 60 );
```

Over the cap, the user gets "Estás mandando muchos mensajes..." inside
the 24h service window (no template cost).

## Resetting test/demo data

```bash
wp eval 'Mantia_E2E::cleanup();'   # wipes anything created by E2E personas
wp eval 'Mantia_Fixture_Seeder::seed();' # restores scheduled state of demo matches
```

The cleanup is safe for production — it only touches entities whose
phone numbers start with `9999000` or whose titles start with `__E2E__`.
