# Mantia

WhatsApp-first prediction-pool ("penca") plugin for WordPress. Built on top of
[Automattic/agents-api](https://github.com/Automattic/agents-api) and
[lezama/openclawp](https://github.com/lezama/openclawp).

Mantia lets a group of friends, family or coworkers run a tournament prediction
pool entirely through WhatsApp — no app to install, no signup form. Send a
match score to the bot, and it routes the prediction to every penca you're in
for that competition. Tap a public link to see the live ranking.

## What's inside

| Surface | Purpose |
|---|---|
| WhatsApp bot | Onboarding, joining pencas, predicting matches, viewing standings |
| 5 CPTs | `mantia_match`, `mantia_prediction`, `mantia_group`, `mantia_user`, `mantia_competition` |
| 16 abilities | Reusable tools (`mantia/register-prediction`, `mantia/get-standings`, …) registered via the Abilities API |
| 1 agent | `mantia` — Claude Haiku 4.5 with Spanish system prompt |
| Public web views | `/`, `/penca/<competition>/`, `/penca/g/<token>`, `/penca/me/<token>` |
| Workflows | Auto-resolution + reminders via cron |

## Layer discipline

Mantia depends on two upstream substrates and writes nothing platform-generic
in its own codebase:

- **agents-api** ships the `wp_register_ability` / `wp_register_agent` primitives,
  workflow `${vars.*}` + `foreach` bindings, channel sender_id extraction, and
  the tool-execution context-before-validate behavior.
- **openclawp** ships the WhatsApp Cloud API ingress, REST chat dispatcher,
  conversation store, runtime-context passthrough, and the
  `openclawp_pre_chat_turn` filter that Mantia uses for deterministic shortcuts.

If a fix would benefit any other agent on WordPress, it lives in one of those
two repos, not here.

## Quick start

Requires WordPress 7.0+ (Abilities API + Connectors live in core).

```bash
cd wp-content/plugins/
git clone https://github.com/Automattic/agents-api.git
git clone https://github.com/lezama/openclawp.git
git clone https://github.com/lezama/mantia.git
wp plugin activate agents-api openclawp mantia
```

Configure a WhatsApp Cloud API number under **wp-admin → openclaWP → WhatsApp**
and point Meta's webhook at `/wp-json/openclawp/v1/whatsapp/webhook`. The
`mantia` agent is auto-registered and accepts inbound messages.

## E2E tests

The full system is exercised end-to-end through the same code path
production traffic uses — no mocks.

```bash
# Local (Docker + wp-env): boots a fresh WP with agents-api + openclawp
# cloned from their upstream main branches.
bin/e2e.sh

# Remote: against any WP install you can SSH to (Studio, wp.com Atomic, etc.)
MANTIA_TARGET=ssh MANTIA_SSH=user@host bin/e2e.sh
```

Scenarios under `tests/e2e/`:

- `penca-lifecycle.php` — cold onboarding → create penca → invite → 3 friends join → predict via tap → match finishes → leaderboard scored
- `aliases-cpt.php` — competitions + admin-editable aliases live in post_meta, resolver routes friendly hints to canonical slugs
- `rate-limit.php` — per-phone throttle protects LLM budget
- `web-routes.php` — every public `/penca/*` route renders with the right copy + recovery CTAs

## CI

`.github/workflows/e2e.yml` runs the full suite on every push + PR against
WordPress trunk with the latest `main` of agents-api and openclawp.

## License

GPL-2.0-or-later — same as WordPress.
