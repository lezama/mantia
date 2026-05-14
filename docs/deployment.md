# Deployment

How to take Mantia from `git clone` to a WhatsApp number that actually
answers predictions.

## Requirements

| Component | Version | Why |
|---|---|---|
| WordPress | 7.0+ | Abilities API + Connectors in core |
| PHP | 8.1+ | Type-system reliance (enums, readonly props) |
| Sibling plugins | latest `main` | `Automattic/agents-api` + `lezama/openclawp` |
| LLM provider | Anthropic API key | Default agent uses Claude Haiku 4.5 |
| Meta Business account | one number, verified | WhatsApp Cloud API |

## Bootstrap a new install

### 1. Drop the three plugins into wp-content/plugins/

```bash
cd wp-content/plugins/
git clone https://github.com/Automattic/agents-api.git
git clone https://github.com/lezama/openclawp.git
git clone https://github.com/lezama/mantia.git
```

For wp.com Atomic / Studio: rsync the directories into
`htdocs/wp-content/plugins/` over SSH, or use Studio Sync.

### 2. Activate in order

```bash
wp plugin activate agents-api openclawp mantia
```

Order matters: agents-api registers `wp_register_ability` /
`wp_register_agent`; openclawp consumes them; mantia consumes both.

Activation hooks register all CPTs, seed default competitions and
demo fixtures, and flush rewrite rules. If `Mantia_Competitions::all()`
is empty after activation, run `wp eval 'Mantia_Competitions::seed_defaults();'`.

### 3. Configure permalinks

```bash
wp rewrite structure "/%postname%/" --hard
wp rewrite flush --hard
```

WP's default plain permalinks (`?p=N`) break every `/penca/*` route.

### 4. LLM provider

WordPress 7.0 ships the Connectors UI. wp-admin → Settings → Connectors →
**Anthropic** → paste your API key. The Mantia agent (Claude Haiku 4.5)
will pick it up automatically.

For wp-cli setup:

```bash
wp option set wordpress_ai_client_anthropic_api_key "sk-ant-api03-..."
```

### 5. Meta WhatsApp Cloud API

In Meta Business platform:

1. Create / pick an App with the **WhatsApp** product enabled
2. Note your **Phone Number ID** (under WhatsApp → API Setup)
3. Generate a **System User token** with scopes
   `whatsapp_business_messaging` + `whatsapp_business_management` —
   see `docs/operations.md` for the click-through
4. Configure the webhook:
   - **Callback URL**: `https://<your-site>/wp-json/openclawp/v1/whatsapp/webhook`
   - **Verify token**: any string you'll re-use in step 6
   - **Subscribe** to `messages`
5. Copy the **App Secret** from App Settings → Basic
6. In wp-admin → openclaWP → WhatsApp, paste:
   - Phone Number ID
   - Permanent Access Token
   - App Secret
   - Webhook Verify Token (same as step 4)
   - Default agent: `mantia`
   - Owner Phone (E.164, no `+`)

### 6. Verify

Send "hola" from your phone to the bot's number. Within ~2 seconds you
should see the onboarding menu. If not, tail the debug log
(`docs/operations.md` → Logs).

## Studio Sync vs SSH

For wp.com Atomic sites, both work:

| Path | Pro | Con |
|---|---|---|
| **Studio Sync** | One-click from local Studio site → remote. Pushes plugin + DB. | DB sync can pile up if remote has new sessions/predictions. Use selectively. |
| **SSH + rsync** | Granular, fast, no DB risk. | Manual; need to flush wp cache after. |

The E2E suite (`bin/e2e.sh`) defaults to SSH for remote testing — point
`MANTIA_SSH=<user>@<host>` at any wp-env or Atomic install.

## CI / fresh-env testing

`.github/workflows/e2e.yml` does the whole bootstrap automatically on
every push:

1. Boots wp-env with WordPress trunk
2. Clones `Automattic/agents-api#main` + `lezama/openclawp#main` as
   sibling plugins
3. Activates the three plugins, seeds competitions + fixtures, sets a
   fake bot phone (so URL builders work) + fake WhatsApp settings
4. Flushes rewrites
5. Runs every scenario in `tests/e2e/*.php`

Total runtime: ~2 minutes.

## Upgrading mantia3

```bash
cd /Users/miguel/dev/a8c/mantia
git pull
rsync -avz -e "ssh -o ConnectTimeout=15" \
  --exclude='.git' --exclude='.github' --exclude='node_modules' \
  ./ mantia3.wordpress.com@ssh.wp.com:htdocs/wp-content/plugins/mantia/
ssh mantia3.wordpress.com@ssh.wp.com "cd htdocs && wp cache flush"
```

Same pattern for agents-api and openclawp (point at their respective
source dirs).
