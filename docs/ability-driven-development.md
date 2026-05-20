# Ability-driven development for agents-api consumers

Mantia is a reference implementation of ability-driven development on top of
`Automattic/agents-api`. This doc explains the pattern so other agents
can adopt it without reinventing the test stack.

## The core idea

**Abilities are the unit.** Everything else — the agent's system prompt,
the WhatsApp router, the LLM tool calls, the REST endpoints — is a
*composition* of abilities. If the abilities are correct in isolation,
the composition has fewer places to be wrong.

Three corollaries follow:

1. **Each ability has a declared input + output schema.** The agent
   loop validates input before `execute_callback` runs, and consumers
   can validate output after. The schema is the contract.
2. **Each ability is testable in isolation** without spinning up the
   agent, the LLM, or a WhatsApp webhook. You pass an input array, you
   get back an array or `WP_Error`. That's it.
3. **The "prompt" is a separate concern.** Voice / tone / when-to-call
   each ability lives in `class-mantia-agent.php::system_prompt()`. The
   abilities themselves don't know about voice. They have one job
   (execute their contract) and they do it.

## What this gets you

- **Refactor freely**: swap the LLM model, rewrite the prompt, change
  the natural-language router — the abilities don't care.
- **Cheap unit tests**: ~10ms per ability test vs ~2-3s per LLM-driven
  E2E test. Run them on every PR.
- **Schema docs come for free**: the agent loop already needs them, and
  the same schema doubles as the API contract for any non-agent caller.
- **Voice regressions don't cascade**: a prompt change can't break the
  business logic, only its phrasing.

## The two layers

| Layer | Where | Cycle time | What it catches |
|---|---|---|---|
| **Ability unit tests** | `tests/abilities/*.php` | ~10ms each | Wrong contract, missing edge case, schema drift |
| **Agent prompt tests** | `promptfoo/promptfooconfig.yaml` | ~1-3s each (LLM call) | Voice regression, wrong tool selection, hallucinated outputs |

Both run on the same codebase; they answer different questions. Skip
neither.

## Ability unit tests — the recipe

All 16 Mantia abilities have a test in `tests/abilities/`:

| Ability | Archetype | Highlights |
|---|---|---|
| [register-prediction](../tests/abilities/register-prediction.php) | State / canonical | Resolver fallback, auto-routing to N pencas |
| [join-group](../tests/abilities/join-group.php) | State / side-effect | Membership + active group + name capture, idempotency |
| [create-group](../tests/abilities/create-group.php) | State / validation | Required-field, competition whitelist, default fallback |
| [set-active-group](../tests/abilities/set-active-group.php) | State / dual-mode | group_id branch + invite_code branch + cold-user error |
| [resolve-match](../tests/abilities/resolve-match.php) | State / workflow | Manufactures fixture + asserts scoring rules fire |
| [get-standings](../tests/abilities/get-standings.php) | Read / canonical | Scope variants, phone → active-group, limit clamp |
| [get-my-groups](../tests/abilities/get-my-groups.php) | Read / error | Stable error code for cold user, is_active consistency |
| [get-upcoming-matches](../tests/abilities/get-upcoming-matches.php) | Read / tagged | has_prediction flag accuracy with + without user |
| [get-match-result](../tests/abilities/get-match-result.php) | Read / id-required | Empty match for unknown id (not error) |
| [get-user-history](../tests/abilities/get-user-history.php) | Read / list | Cold user empty + auto-fill populates |
| [get-whatsapp-home](../tests/abilities/get-whatsapp-home.php) | Read / composite | Cold vs authed paths, has_prediction tagging, pending cap |
| [score-prediction](../tests/abilities/score-prediction.php) | Pure function | 5/3/1/0 rules across exact / diff / winner / miss / draw |
| [get-finished-unresolved-matches](../tests/abilities/get-finished-unresolved-matches.php) | Read / no-input | Each entry has scoreable shape |
| [fetch-fifa-result](../tests/abilities/fetch-fifa-result.php) | Read / external | Graceful for bad + good ids (no exception) |
| [get-match-reminder-targets](../tests/abilities/get-match-reminder-targets.php) | Read / workflow | dedupe_key present, hours_ahead respected |
| [get-daily-digest-targets](../tests/abilities/get-daily-digest-targets.php) | Read / workflow | One-per-user invariant, dedupe_key present |

Pattern (from `register-prediction.php`):

```php
require_once __DIR__ . '/../lib.php';

Mantia_E2E::start( 'ability: mantia/register-prediction' );

// 1. Bootstrap a clean persona
$persona = array( 'phone' => '9999000801', 'name' => '__E2E__ Owner' );
Mantia_E2E::cleanup_persona( $persona );

// 2. Build minimum fixtures (user + group + match)
// ... seed-style setup using the bot itself, OR direct Repository calls

// 3. Call the ability in isolation
$result = Mantia_E2E::call_ability( 'mantia/register-prediction', array(
    'user_phone' => $persona['phone'],
    'match_id'   => $match_id,
    'home_score' => 2,
    'away_score' => 1,
) );

// 4. Assert against the declared output_schema
Mantia_E2E::assert_ability_output( 'mantia/register-prediction', $result );

// 5. Assert business behavior
Mantia_E2E::assert_eq( 2, (int) $result['prediction']['home_score'], 'home persisted' );

Mantia_E2E::cleanup_persona( $persona );
Mantia_E2E::finish();
```

Five cases per ability cover most ground:

1. **Happy path** — explicit arguments, valid output.
2. **Resolver fallback** — when the LLM passes natural-language hints
   instead of canonical IDs (e.g., `first_team` instead of `match_id`).
3. **Error contract** — what does `WP_Error` look like for the most
   common invalid input? Agents need stable error codes to recover.
4. **Side-effect coverage** — auto-routing, idempotency, propagation
   through related state.
5. **Schema enforcement** — does the response always have the keys
   downstream consumers depend on?

The `Mantia_E2E` test harness provides:

- `call_ability( $name, $input )` — invokes via `wp_get_ability()` so
  schema validation runs the same way the agent loop runs it.
- `assert_ability_output( $name, $result )` — walks the declared
  `output_schema` and fails on missing required keys + wrong primitive
  types. Catches the 80% of contract drift without a full JSON Schema
  dependency.
- `cleanup_persona( $persona )` — wipes one test user + their groups +
  predictions without nuking parallel test data.

## Agent prompt tests — the recipe

See [`promptfoo/promptfooconfig.yaml`](../promptfoo/promptfooconfig.yaml).

PromptFoo gives you:

- **LLM-as-judge assertions** (`type: llm-rubric`) for voice rules that
  string-matching can't catch ("the reply is in rioplatense voseo with
  no enthusiasm").
- **Tool-call assertions** to verify the model picks the right ability
  for a given user input (`contains: register-prediction`).
- **Adversarial coverage** — prompt injection, code-switching, idioms,
  misspellings, insults. One YAML row per case, no PHP setup needed.

Cost note: each row runs an LLM call against Claude Haiku 4.5. The full
~15-row suite is ~30 seconds and a few cents. Run it before any
non-trivial system_prompt change.

```bash
bin/promptfoo.sh           # full suite, then UI tip
bin/promptfoo.sh --quiet   # CI mode
```

## The synthesis

A new ability gets shipped via this loop:

1. **Define the schema first.** What's the input shape, what's the
   output shape, what error codes can fire? Add it to
   `class-mantia-abilities.php` with `input_schema` + `output_schema`
   + `execute_callback`.
2. **Write the unit test** in `tests/abilities/<slug>.php` covering
   the five cases above. Run it: `bin/e2e.sh abilities/<slug>`.
3. **Mention the ability in the agent's system prompt.** One sentence:
   *"If user says X, call `mantia/<slug>` with arguments Y."*
4. **Add a prompt test** in `promptfooconfig.yaml` asserting the
   selection: `contains: mantia/<slug>` for a representative input.
5. **Don't write a flow E2E for this.** The flow tests in
   `tests/e2e/*.php` exercise compositions, not individual abilities.
   If you find yourself wanting one, the ability test was probably
   too thin.

## What this is NOT

- A replacement for end-to-end tests. The flow tests catch
  composition bugs — the router calling the wrong ability, two
  abilities racing on state, the WhatsApp ingress losing the
  sender's profile name. Keep them.
- A claim of complete coverage. Schema validation is shallow; it
  doesn't catch all type errors. If an ability's behavior depends on
  a nested object's deep shape, write a custom assertion.
- A framework. It's a *pattern* — three helpers (`call_ability`,
  `assert_ability_output`, `cleanup_persona`) plus a shared file
  layout. Copy it into your own agent.

## How Mantia got here

A cumulative QA cycle (5 rounds, see `tests/qa-output/round-*-archive/`)
surfaced bugs that lived at every layer:

- **Ability level**: `register-prediction` auto-routing to N pencas
  needed schema discipline so the agent could tell which pencas
  received the write.
- **Prompt level**: voice drift in the agent's `hola` response ("Hola!"
  → "Hola.", removed "Mundial 2026" references, switched to voseo).
- **Composition level**: bare scores fell through the router into the
  LLM, which hallucinated a wa.me URL with placeholder text.

The pattern above evolved as the bugs surfaced. Documenting it now so
the next agents-api consumer doesn't have to rediscover it.
