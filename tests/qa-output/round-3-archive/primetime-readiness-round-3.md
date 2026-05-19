# Mantia · primetime-readiness — Round 3

**Generated:** 2026-05-19T23:30:00Z
**Personas run:** organizer-cold (6 verifications), multi-penca (5 verifications), lurker-web-only (6 verifications)
**Verdict:** **SHIP-READY** — 16/18 verifications pass, no new blockers, 2 minor edge bugs and 1 cosmetic fixed in-flight.

R3 was a regression-verification cycle with shorter, focused prompts +
stub-first findings.json writing to address the watchdog stalls from R2.
All 3 agents completed cleanly.

---

## Round-1 + Round-2 fix verification

| ID | Issue (origin) | R3 status |
|---|---|---|
| R1 #1 | Share handler leaked `view_token` | ✅ Closed — found a RESIDUAL leak in `handle_create_group` reply via `member_lines(include_private_link=true)`. Fixed in commit `aa08922`: dropped the param entirely; `member_lines()` never inlines the private link in any context now. |
| R1 #2 | Bare score silent-fail | ✅ Closed — bot now replies "Recibí *2-1* pero no sé para qué partido..." with pending matches list. |
| R1 #3 | Stale Mundial copy | ✅ Closed across all surfaces (bot opener, home CTA, themed 404 recovery, agent system prompt). |
| R1 #4 | Edit-guard past-kickoff | (Code deployed in `handle_quick_score`; future cycle with a past-kickoff seed should explicitly fire the rejection.) |
| R1 #5 | REST API enumerable | ✅ Closed — all 4 CPTs return `rest_no_route` 404 to anonymous callers. |
| R2 paper-cut 1 | "1 jugadores" plural | ✅ Closed — split into two `_n()` calls per row description. |
| R2 paper-cut 2 | Bare-score fallback contradicts empty list | ✅ Closed (with edge case caught below). |
| R2 paper-cut 3 | Rate-limiter trips multi-penca pattern | ✅ Closed — 3 pencas back-to-back without throttle. |
| R2 polish | "Hola!" → "Hola." | ✅ |
| R2 polish | "→" arrow on competition subtitle | ✅ |
| R3 polish | Unified 404 H1 across all /penca/* paths | ✅ |
| Bonus | Stashed-score auto-apply on next tap | ✅ — works across all 3 pencas in one round trip. |
| Bonus | mis-pencas always Interactive List with counts | ✅ |
| Bonus | Phone number fallback for nameless users | ✅ (`+598 99 139 203` instead of `Jugador ·9203`). |
| Bonus | PWA paths trailing-slash optional | ⚠️ `/manifest.json` works (301 → /). `/service-worker.js` (no slash) still hard-404 at nginx (wpcom Atomic intercepts .js as static before WP rewrites). Functionally fine — browser uses the trailing-slash URL via link tag + register(). External Lighthouse probes will fail but no user-facing breakage. Documented in commit comment. |

---

## New findings (R3)

### Paper-cut: stash collision when repeat-of-last-score
- **Where:** `class-mantia-whatsapp-flow.php` — bare-score handler.
- **What:** After "tap match → bot applies score → stashed_score pending_match cleared", the user types the SAME score again. Bot silently re-applies the stashed score to the last predicted match instead of replying "ya pronosticaste todos los pendientes". Reproduces deterministically.
- **Severity:** edge-case paper-cut. The user typed a score, *something* happened (the match got "re-confirmed" to the same value), no data corruption. But the contradictory UX is confusing.
- **Fix sketch:** in the bare-score branch, after the `pending_match` check, also clear `pending_score_key` before calling `set_transient` for the new attempt. Or detect when the new stash equals a recent prediction and short-circuit to the all-predicted message.

### Paper-cut: competition label mismatch in handle_pending
- **Where:** `class-mantia-whatsapp-flow.php` — `handle_pending()` bucket labels.
- **What:** When user's 3 pencas are all "📆 Libertadores semanal" but pending matches are queried via the parent competition (libertadores-2026), the bucket header reads "🥇 Libertadores 2026 — 2 partidos". Confusing — user expects their joined-penca label.
- **Severity:** cosmetic. Matches are correct, just the label feels wrong.
- **Fix sketch:** when grouping by competition for the pending list, prefer the user's `active_penca.competition_label` over the match's source slug.

### Paper-cut: handle_pending pluralization (fixed in this cycle)
- **Where:** `class-mantia-whatsapp-flow.php:1564, 1569`
- **What:** "Te faltan *1* pronósticos:" and "*Libertadores semanal* — 1 partidos" — hardcoded plural.
- **Status:** ✅ Fixed in commit `1ca90c8` — both now use `_n()`.

---

## Recommended ordering for the 2 remaining R3 paper-cuts

Both live in `class-mantia-whatsapp-flow.php`. Single PR:

1. **Stash collision** — ~5 lines around `pending_score_key` set/get. Test by typing same score twice.
2. **Bucket label preference** — ~3 lines in `handle_pending`, prefer the user's active-penca competition_name over the match's parent slug.

Round 4 (when you want) would verify these landed + re-check edit-guard for past-kickoff with a seeded historical match.

---

## What's truly ship-ready

Three rounds of multi-persona QA found NO outstanding blockers. The
remaining work is paper-cuts at the cosmetic edge. The killer features
(auto-routing, stashed-score, /sumate/ one-message join, themed 404,
public share-token separation) all hold up under cross-persona scrutiny.

The QA platform itself (bin/qa-* + tests/qa-*) survived R3 with the
stub-first writing pattern — zero watchdog stalls this round vs 3/4 in
R2. The pattern is documented in `docs/qa-platform.md` for any future cycle.

**Verdict: SHIP IT.**
