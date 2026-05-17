---
name: mantia-bot-flow
description: Conventions for adding or modifying commands in the WhatsApp bot router (includes/class-mantia-whatsapp-flow.php). Use when touching ANYTHING in that file - new command, regex change, handler refactor, reply template tweak. Background knowledge - Claude reads this without the user invoking, never user-invokable.
user-invocable: false
---

# Mantia bot flow conventions

The WhatsApp router is the single hottest file in Mantia: every inbound message flows through it before maybe falling through to the LLM. Regressions here are silent — a too-greedy regex can steal traffic from another flow without anyone noticing until a user complains. These rules are what I've learned hand-rolling commands in it; apply them every time.

## The router contract

`Mantia_Whatsapp_Flow::maybe_handle_command( $turn )` returns:

- **`array { reply: string, interactive?: array, completed?: bool }`** — the bot answered, openclaWP sends the reply and skips the LLM.
- **`null`** — fall through to the LLM with the raw user message.

A handler is ANY function that returns one of those shapes. Every regex match in the router calls a `handle_*()` and returns its array.

## Adding a new command — checklist

1. **Regex position matters.** The router falls through in order: payload routes (`mantia:cmd:*`) → text shortcuts (specific to general) → score-line catch-all → LLM. Put your new regex *above* a more-general one (e.g. a "Argentina gana todo" pattern must land before the generic `^[a-zA-Z]+` LLM fallback).

2. **Anchor with `^…$`** and use the `u` modifier for Spanish characters. Do not anchor with `^.+` (greedy) — explicit alternations only.

3. **Country-vocab** — never literal "penca" in user-facing copy. Use `Mantia_Vocab::word( 'noun', $identity['phone'] ?? '' )` (or `'plural'`/`'new_adj'`/`'active_adj'`/`'article_indef'`). The handler is country-blind; vocab decides.

4. **Fan-out** — predictions span every penca the user has in the match's competition. New write paths MUST go through `Mantia_Abilities::register_prediction` (not directly to `Mantia_Repository::register_prediction`), which handles fan-out + window guard. Direct repository calls are for read paths only.

5. **Privacy** — predictions are private until kickoff. If a new command surfaces ANY scoreline, gate it via `Mantia_Repository::group_consensus_for_match()` (which returns `[]` pre-kickoff) or restrict to `$user_id == current viewer`. Never load another user's prediction by `user_id` in a handler that the requesting user didn't author.

6. **24-hour WhatsApp Cloud API window** — outbound messages outside the last-inbound-24h window are pay-template territory and we don't ship those. Handlers run in response to inbound = always inside window. CRON-driven pushes are NOT (would need a `last_inbound_ts` per-user store, none exists yet). When in doubt: if your handler isn't on the inbound→reply path, talk to me first.

7. **Pending-state transients** — if your flow needs multi-turn state (e.g. "user typed 'crear' then we asked for a name then they typed the name"), use `set_transient( self::pending_*_key( $phone ), $value, 15 * MINUTE_IN_SECONDS )`. Clear with `delete_transient` after consuming. Never carry state through user-visible echo strings.

8. **Reply structure**:
   - Lead with the action confirmation (✅ or ❌ + verb).
   - One blank line.
   - Context (member list, what changed, what's next).
   - Optional `_emphasised italic suggestion of next step_` at the end.
   - Always include `📱 Tu link privado: <url>` when reply is group-context — `member_lines()` does this automatically; call it.

9. **Interactive buttons** — max 3 buttons per `'type' => 'button'` reply. Titles ≤ 20 chars (WhatsApp truncates). Use ID prefix `mantia:cmd:*` for known commands; `mantia:newcomp:*`, `mantia:switch:*`, `mantia:match:*` for parameterised actions.

10. **Tests** — every new command needs a row in `tests/e2e/flows-narrative.php` (any-command coverage) AND a dedicated assertion if behaviour is non-trivial (e.g. consensus has its own `consensus-privacy.php`).

## When you're tempted to bypass these

- "It's just a debug helper" → still goes through the same code path, same regex; either add it or don't.
- "The 24h-window doesn't apply because…" → it applies, full stop. Talk to me.
- "I'll add the vocab call later" → no; the bot must read identically for UY/AR/BR from commit-one.

## Files touched together

When changing the router, you almost always also need:

- `tests/e2e/flows-narrative.php` — add a step exercising the new command
- `includes/class-mantia-rest.php` if the new feature has a web counterpart
- `CLAUDE.md` if the change relaxes or tightens one of the rules above
