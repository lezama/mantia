# Configuration reference

Every extension point Mantia exposes. Use these instead of forking.

## WordPress options

| Option | Default | Purpose |
|---|---|---|
| `mantia_bot_phone_e164` | empty | Bot's WhatsApp number in E.164 digits (no `+`). Drives `wa.me/<phone>` URL builders. Set in wp-admin → openclaWP → WhatsApp → Owner Phone, or via `wp option set`. |
| `mantia_e2e_base_url` | empty | E2E-test override for `home_url()` (used by CI to route over the docker network). Leave unset in production. |
| `openclawp_whatsapp_settings` | (array) | Cloud API credentials. Managed via wp-admin → openclaWP → WhatsApp. |

## Filters

### Routing & registration

| Filter | Signature | Default | What it does |
|---|---|---|---|
| `mantia_render_home` | `bool` | `true` | Return `false` to keep the WordPress theme's homepage instead of Mantia's all-black landing. |
| `mantia_home_first_message` | `string` | `'hola'` | The pre-filled WhatsApp message in the homepage QR. Useful for sponsored deployments that want a campaign anchor. |
| `mantia_qr_image_url` | `(string $url, string $payload, int $size)` | api.qrserver.com URL | Swap the QR generator backend (e.g. point at a self-hosted SVG endpoint). |

### Permissions

| Filter | Signature | Default | What it does |
|---|---|---|---|
| `mantia_ability_permission` | `(bool $allowed, string $ability_name, $input)` | `current_user_can('manage_options')` | Per-ability gate. Useful for opening up a read-only ability to anonymous REST callers without unlocking destructive ones. |
| `mantia_whatsapp_user_initiated_only` | `bool` | `true` | When true, the bot won't send outbound messages outside the 24h service window (no template costs). Flip to `false` only if you've registered Utility/Marketing templates with Meta. |
| `mantia_enable_outbound_whatsapp_workflows` | `bool` | inverse of `user_initiated_only` | Gates the reminder + daily-digest workflows. |

### Rate limiting

| Filter | Signature | Default | What it does |
|---|---|---|---|
| `mantia_rate_limit_max` | `int` | `20` | Max inbound messages per phone within the window. |
| `mantia_rate_limit_window_seconds` | `int` | `60` | Rolling window for the cap. |

### Scoring

| Filter | Signature | Default | What it does |
|---|---|---|---|
| `mantia_scoring_rules` | `array` | Premier-League-style | Override the points awarded per prediction. Default: `5` exact, `3` goal-difference correct, `1` winner correct, `0` otherwise. |

### Results

| Filter | Signature | Default | What it does |
|---|---|---|---|
| `mantia_fetch_match_result` | `($null, array $match) => array\|WP_Error\|null` | `null` | Return a `{home_score, away_score, status, source}` array to override the admin-entered result with one from an external provider (api-football, ESPN, etc.). Return `WP_Error` to skip; return `null` to fall back to match meta. |

## Actions

| Action | Args | Fired when |
|---|---|---|
| `mantia_match_resolved` | `(int $match_id, array $scored, array $result)` | A match was resolved and every prediction was scored. Use this to broadcast a "match ended" message to groups, push to analytics, etc. |
| `mantia_dispatch_message_requested` | `(array $payload)` | A workflow asked to send an outbound WhatsApp message via `agents/dispatch-message`. The openclawp WhatsApp channel listens for this. |

## Constants

Defined in `mantia.php`:

| Constant | Value | Purpose |
|---|---|---|
| `MANTIA_VERSION` | from plugin header | Versioning for cache busting / migrations |
| `MANTIA_PATH` | plugin directory | Base path for asset resolution |
| `MANTIA_URL` | plugin URL | Frontend asset URLs |

## Custom Post Types

Public CPTs with admin UI (`show_ui: true`):

| CPT | Slug | Hierarchical | Purpose |
|---|---|---|---|
| Match | `mantia_match` | No | One fixture row. Meta: external_id, home_team, away_team, kickoff_gmt, kickoff_ts, phase, status, home_score, away_score, resolved, competition_id |
| Prediction | `mantia_prediction` | No | One user's score guess for a match in a specific group. Meta: user_id, match_id, group_id, predicted_home_score, predicted_away_score, points, scored, scoring_reason |
| Group | `mantia_group` | No | A penca. Meta: invite_code, group_slug, group_view_token, competition_id |
| User | `mantia_user` | No | A WhatsApp player. Meta: phone, whatsapp_recipient, group_ids, active_group_id, user_view_token |
| Competition | `mantia_competition` | Yes | A tournament. Hierarchical so window-views (`libertadores-semana`) can point at a parent (`libertadores-2026`). Meta: emoji, window_days, is_default, sort_order, aliases. Admin meta-boxes registered for editing aliases + config. |

## Abilities catalog

All 16 abilities live in the `mantia` category and are exposed as REST
endpoints (`show_in_rest: true`):

| Ability | Annotations | What it does |
|---|---|---|
| `mantia/register-prediction` | destructive | Save a prediction. Auto-routes to all of user's pencas in the match's competition. |
| `mantia/get-standings` | readonly | Leaderboard rows for a group or global. |
| `mantia/get-upcoming-matches` | readonly | Matches in next N hours, with per-match `has_prediction` flag for the user. |
| `mantia/get-match-result` | readonly | One match's data + final score if available. |
| `mantia/get-user-history` | readonly | All predictions a user has made in a group. |
| `mantia/join-group` | destructive | Add user to a group by invite code. |
| `mantia/create-group` | destructive | Create a new penca. Optional `competition_id` arg targets a specific tournament. |
| `mantia/get-my-groups` | readonly | User's groups + active group id. |
| `mantia/set-active-group` | destructive | Switch active group (or join by invite). |
| `mantia/get-whatsapp-home` | readonly | One-call snapshot for the WA "home" view. |
| `mantia/get-finished-unresolved-matches` | readonly | Matches with `status=finished` + `resolved=0`. Drives the resolver workflow. |
| `mantia/resolve-match` | destructive | Score every prediction for a finished match. Fires `mantia_match_resolved`. |
| `mantia/fetch-fifa-result` | readonly | Read result via `Mantia_Results_Fetcher` (which respects the filter). |
| `mantia/score-prediction` | readonly | Pure function: given predicted + real scores, returns points + reason. |
| `mantia/get-match-reminder-targets` | readonly | (workflow-fed) recipients for the 2h pre-kickoff reminder. |
| `mantia/get-daily-digest-targets` | readonly | (workflow-fed) recipients for the 8am summary. |

The last two are only registered when outbound workflows are enabled
(see the `mantia_enable_outbound_whatsapp_workflows` filter).

## Workflows

Three cron-driven workflow CPT posts registered on `wp_agents_api_init`:

| Workflow | Cron | What it does |
|---|---|---|
| `mantia/resolve-matches` | every 15 minutes | Find finished-unresolved matches, score each |
| `mantia/match-reminders` | every 10 minutes (only when outbound enabled) | Ping users without a prediction 2h before kickoff |
| `mantia/daily-digest` | once a day (only when outbound enabled) | 8am summary to every active user |

Unregister with:

```php
\AgentsAPI\AI\Workflows\WP_Agent_Workflow_Action_Scheduler_Bridge::unregister( 'mantia/match-reminders' );
```
