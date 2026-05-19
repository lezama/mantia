# Mantia · primetime-readiness

**Generated:** 2026-05-19T20:15:00Z
**Personas run:** organizer-cold (21), multi-penca (11), organizer-returning (12), lurker-web-only (12), member-invited (12)
**Total raw findings:** 68 | **Unique after dedupe:** 24
**Verdict:** needs-2-days — 5 blockers, all narrowly scoped (3 of them in one file). Web surface and core flows are in excellent shape; the gating issues are security copy in the bot share path, silent-fail on bare scores, and stale "Mundial" copy across home/404/bot. None require new architecture — most are search-and-replace or single-handler fixes.

---

## TL;DR — ship-blocking issues

### 1. Share/confirmation handler leaks the edit-token (view_token) as if it were a share link
- **Why it's a blocker:** This is the core privacy bug. The bot reply to `compartir` / `mantia:cmd:share-link` and to penca-creation confirmation includes `/penca/me/<view_token>` ("Tu link privado") and `/penca/g/<view_token>` in the same message that says "Reenviá la tarjeta a cualquier grupo de WhatsApp" + "Invitar amigos (1 toque)". Architecture for a separate `share_token` exists (commit 67f69f3) but the bot still emits `view_token`. Anyone who copy/pastes the wrong URL hands edit-capable access to a group. The lurker persona also confirmed via `/me/share/` that the separation works when invoked — so this is purely the bot reply path.
- **Personas that flagged it:** organizer-cold (×2 findings, on crear-penca and share-link), organizer-returning (×1 on share-link). 3 of 5 personas independently surfaced it. The member-invited persona separately confirmed the `share_token`/`view_token` split DOES work on /me/share/ — so the fix is to plumb the existing field into the bot reply, not to invent it.
- **Where:** `includes/class-mantia-whatsapp-flow.php` — `handle_share_link` and the penca-creation confirmation builder. Lookup `build_join_landing_url` and any "Tu link privado" string.
- **Evidence:** organizer-cold: `'📱 Tu link privado: https://mantia3.wpcomstaging.com/penca/me/d7b808066db06b48781d7386'` returned in the share reply. organizer-returning: `'🌐 Ver standings en la web: https://mantia3.wpcomstaging.com/penca/g/0ab4a7ff7812176ef70979bc'` where that token is `groups[0].view_token` and `share_token=''` (never minted for this user).
- **Fix:** In the share reply, (a) drop "Tu link privado" entirely (the user already has the chat — they don't need a URL), (b) replace `/penca/g/<view_token>` with `/penca/g/<share_token>/sumate/` or remove the "Ver standings en la web" line altogether (wa.me 1-tap is enough), (c) backfill `share_token` lazily on first share call for groups where it's empty. Same patch resolves the organizer-cold crear-penca finding (same builder).
- **Estimated effort:** M (single file, but needs the backfill-on-read for existing groups + a quick smoke test that share replies emit only `share_token` URLs).

### 2. Bare score with no pending-match context silently returns empty reply
- **Why it's a blocker:** This is the single worst UX failure across QA. The killer feature — "type 2-1, write to all your pencas" — works perfectly when there IS a pending-match transient (multi-penca persona confirmed routing to 3 pencas in one call). But typing `2-1` / `2 a 1` / `Boca 3 River 1` with no match tapped first returns `reply:''`, `interactive:null`, `fell_through_to_llm:true`. The user gets dead air — worse than an explicit "no entendí". Multi-penca explicitly notes the persona's question "does the bot pick the closest match or ask?" — answer is "neither, silent fail".
- **Personas that flagged it:** organizer-cold (×1), multi-penca (×1). 2 of 5 — high impact because every chat-first user will hit this within 5 minutes.
- **Where:** `includes/class-mantia-whatsapp-flow.php:196-201` (score regex match) — when `pending_match` transient is empty/expired, the handler falls through without sending a reply.
- **Evidence:** multi-penca: `bin/sim-wa.sh '+999900004' ... '2-1' → {reply:'', interactive:null, fell_through_to_llm:true}`. organizer-cold: same null reply across `'2-1'`, `'2 a 1'`, `'Boca 3 River 1'`, `'20-19'`.
- **Fix:** When score regex matches but no pending match, either (a) auto-target the next un-predicted match in the active penca's competition and confirm `"Asumiendo Bolívar vs Cerro Porteño — mandá el id si era otro"`, or (b) reply with an Interactive List of pending matches asking which one. Bare minimum: never return an empty string from a matched regex — emit `"No identifiqué el partido. Tocá uno acá ↓"` + the matches picker.
- **Estimated effort:** S (one handler, choose one of the two strategies, add the picker fallback).

### 3. Stale "Mundial" copy across bot + home + 404 (the Mundial regression)
- **Why it's a blocker:** This appears on the very first surface every persona touches. The Mundial was removed from the seed (only Libertadores 2026 + Libertadores semanal exist in pickers — verified clean by organizer-cold, multi-penca, lurker-web). But the bot's `hola` and `ayuda` replies still introduce Mantia as "la app de pronósticos *mundialistas* por WhatsApp", the home CTA says "Ver el ranking del Mundial", and the themed 404 primary recovery CTA across all three /penca/* paths says "Ver Mundial 2026". A cold visitor's first 5 seconds say "this product is about a tournament that doesn't exist."
- **Personas that flagged it:** organizer-cold, multi-penca, organizer-returning, member-invited, lurker-web-only. **5 of 5 personas** — universal regression.
- **Where:**
  - Bot: `includes/class-mantia-whatsapp-flow.php` lines 1261 (hola) and 1379 (ayuda) — `'pronósticos mundialistas'`
  - Home: home template (mantia-home shortcode / template-home.php) — `'Ver el ranking del Mundial'`
  - 404: themed 404 template (likely `class-mantia-404.php`) — `'Ver Mundial 2026'` (one source, surfaces on /penca/no-existe/, /penca/g/bogus/, /penca/me/share/bogus/)
- **Evidence:** member-invited confirmed both bot lines via grep. lurker-web confirmed home CTA `textContent='Ver el ranking del Mundial'` and 404 a11y snapshot `'Ver Mundial 2026'`.
- **Fix:** Three search-and-replaces: `mundialistas` → `de fútbol` (bot), `Ver el ranking del Mundial` → `Ver Libertadores 2026` (home), `Ver Mundial 2026` → `Ver Libertadores 2026` (404 template). Also update the `ayuda` example `Uruguay 2 Portugal 1` (Mundial-flavoured) → `Boca 2 River 1` or `Peñarol 2 Nacional 1`.
- **Estimated effort:** S (4 string replacements across 3 files).

### 4. Edit-guard for past-kickoff matches not verifiable / likely missing
- **Why it's a blocker:** Spec says "Si el match ya empezó (kickoff < now), el bot DEBE rechazar la edición". At 19:31 UTC the organizer-returning persona sent `3-2` for Bolívar vs Cerro Porteño (kickoff `Tue 19 May • 19:00`) and the bot accepted it cleanly, no rejection. Either the seed kickoffs are in a future timezone (test-data issue) or the guard is missing (real bug). Without proof the guard fires, this is unsafe to ship — a user can rewrite predictions during a live match.
- **Personas that flagged it:** organizer-returning (×1).
- **Where:** `includes/class-mantia-whatsapp-flow.php` — `handle_score_pending` / `handle_match_detail`. Verify whether `kickoff_utc < now` check exists.
- **Evidence:** organizer-returning: reply at 19:31 UTC to `mantia:match:6382` (kickoff Tue 19 May 19:00) returned `'Pronóstico actual: *3-2* — mandame uno nuevo (ej *2-1*).'` — no rejection.
- **Fix:** Read the relevant handler, confirm whether a guard exists; if not, add `if ( $match->kickoff_utc < time() ) return $this->reply_kickoff_passed( $match );`. Add a unit test seeding a kickoff 5 min in the past.
- **Estimated effort:** S (assuming guard needs adding) to XS (if it exists and the test fixture timezone is the issue — just document).

### 5. Mantia CPTs publicly enumerable via /wp-json/wp/v2/mantia_*
- **Why it's a blocker:** Mantia's privacy model rests on tokens — predictions are private until /me/ reveal. But `show_in_rest=true` is on for `mantia_prediction`, `mantia_user`, `mantia_group`, `mantia_match`. Anonymous `GET /wp-json/wp/v2/mantia_prediction` returns 10 records titled like `'Florencia QA Returning: River Plate 0-1 Boca Juniors'`. `/mantia_user` exposes 6 player display names, `/mantia_group` exposes 7 group titles. View-tokens themselves stay private (good — `_mantia_*` meta is gated), but enumeration of "who predicted what" defeats the privacy intent.
- **Personas that flagged it:** lurker-web-only (×1).
- **Where:** CPT registrations — likely `includes/class-mantia-cpts.php` (or wherever `register_post_type` is called for the four `mantia_*` types).
- **Evidence:** `GET /wp-json/wp/v2/mantia_prediction → 200, 10 entries with prediction titles in plaintext`.
- **Fix:** Set `show_in_rest => false` on all four CPT registrations. Confirms that nothing in the web surface depends on the v2 REST namespace (Mantia uses its own `/wp-json/mantia/v1` routes). Alternative if any code DOES depend on it: add a `rest_authentication_errors` filter returning empty/forbidden for unauthenticated callers querying these post types.
- **Estimated effort:** XS (4 lines).

---

## Paper-cuts to clean before launch

### Group 1: Stale copy / Mundial residue
Already covered as blocker #3, but related smaller items:
- **organizer-cold:** Help-text pronóstico example is `Uruguay 2 Portugal 1` (Mundial flavour) — swap to `Boca 2 River 1`. (flow.php help block)
- **organizer-cold + multi-penca:** Confirmation post-creación says "_Reenviá la tarjeta de arriba ↑_" but no tarjeta is rendered first in the sim. Either send the share-card AS the parent of this reply, or change copy to "Tocá *Re-enviar* para mandar la tarjeta a tus amigos".

### Group 2: Days-of-week in English vs Spanish on web
- **organizer-cold, member-invited:** Bot match-list/detail shows `Tue 19 May`, `Wed 20 May`, `Thu 21 May`. Web shows `MAR 19 MAY`, `MIÉ 20 MAY`, `JUE 21 MAY`. Inconsistency is glaringly visible if a user switches between bot and web.
- **Where:** `includes/class-mantia-whatsapp-flow.php::format_kickoff` (~line 1909). `gmdate('D j M • H:i', …)` is locale-insensitive — switch to `wp_date('D j M • H:i', …)` or `date_i18n(...)`.

### Group 3: Vocabulary drift — "mis grupos" vs "mis pencas"
- **multi-penca:** Home reply suggests `Escribí *mis grupos* para verlas` but rest of product uses "pencas".
- **organizer-cold + organizer-returning:** Help text lists `*mis grupos*` as the canonical command but UI buttons say "Mis pencas".
- **organizer-cold:** Button after crear-penca says `📤 Re-enviar` (ambiguous — what gets re-enviado?). Use `Compartir` or `Invitar` to align with the rest.
- **Fix:** Single sweep in flow.php — change `mis grupos` to `mis pencas` in the displayed help/home suggestion (regex already accepts both). Same file.

### Group 4: Missing accents (Spanish polish)
- **organizer-cold, organizer-returning:** `Proximos partidos`, `Todavia no hay puntos`, `Despues de que se resuelvan`, `pronostico` (× many), `cargar pronostico`, `mandame`. Mixed with correctly-accented `Pronóstico`/`próximos` elsewhere — adds up to "feels unfinished".
- **Where:** flow.php strings, ayuda template, ranking template.
- **Fix:** Find-replace sweep — `Proximos`/`Despues`/`Todavia`/`pronostico` → tildes.

### Group 5: List/row affordances missing player + prediction counts
- **organizer-cold:** `mis pencas` with N=1 returns plain text + buttons (`Tu penca: *Los del 92* (código `LOSDEL923TFN6`).`) — no Interactive List, no counts. The bare code `LOSDEL923TFN6` appears without context.
- **multi-penca + organizer-returning:** With N≥2 you DO get an Interactive List but row.description is just the torneo (e.g. `📆 Libertadores semanal`). Spec asks for `torneo · N jugadores · M pronósticos` so users see where they have predictions pending.
- **Fix:** Unify — always return Interactive List (even N=1) with description appended as `· N jugadores · M pronósticos`.

### Group 6: Empty-state for fresh joiners on /penca/g/<token>/
- **member-invited:** Pablo joined, hero says "4 jugadores", but TABLA DEL GRUPO renders only "Todavía no hay puntos cargados." He sees no proof he's in the group — no name, no pedestal placeholder, no roster.
- **Fix:** When no scoring rows exist yet, render a "jugadores que se sumaron" list using display names already available from the bot's `👥 Quiénes están (N)` data, OR render pedestal slots with 0-pt rows so the viewer sees themselves in slot 1.

### Group 7: Web share-poster wa.me href is bare domain
- **lurker-web:** `/penca/me/share/<token>/` "Mandar por WhatsApp" button href is `https://wa.me/?text=mantia3.wpcomstaging.com%2F` — no specific path. Recipients see only the domain.
- **Where:** `template-share.php` / `class-mantia-share` wa.me builder.
- **Fix:** Include the share URL (`/penca/g/<share_token>/sumate/` or `/penca/me/<share_token>/`) in the wa.me text param and in the visible card footer.

### Group 8: PWA canonical paths return 404
- **lurker-web:** `/service-worker.js` returns nginx 404 — only `/service-worker.js/` (trailing slash) works. Page registers the trailing-slash version so PWA works, but Lighthouse, PWA Builder, third-party crawlers will fail.
- **Fix:** Add a rewrite for the non-slash variant (or strip trailing slash everywhere).

### Group 9: Share path is slow (465-519ms)
- **organizer-returning:** `compartir` and `mantia:cmd:share-link` take 465-519ms vs `mis pencas` 7-11ms, slim detail 11-16ms, score commit 67ms. ~50× slower. Likely culprits: lazy share_token mint, OG-image re-render on first call, or remote member-roster refetch.
- **Fix:** Profile `handle_share_link`. Cache the share string per group with a short TTL.

---

## Polish opportunities

- **organizer-cold + member-invited:** `Hola!` (single trailing exclamation) is over-cheerful for the voseo tone. Try `Hola,` / `Buenas,` / `Hola — soy *Mantia*…` for a flatter cool tone.
- **multi-penca:** Active-penca indicator inconsistency — body uses ✅, row title prefix uses ✓, inactive uses ▫️ in body and no prefix in row title. Minor visual jitter.
- **organizer-returning:** Tapping a row in `mis pencas` should jump to detail (ranking + partidos) in one tap, not show `Listo, penca activa: X` requiring a second `Resumen` tap.
- **organizer-cold:** `/sumate/` auto-redirect via JS hides the hero from JS-disabled / wa-web users. Either show the hero with explicit CTA, or keep redirect but render a visible fallback hero on first paint.
- **lurker-web:** Competition page subtitle uses en-dash `mar 19 may – jue 21 may` — spec wants arrow `→`. Both valid; spec consistency.
- **lurker-web:** 404 for `/penca/<slug>` echoes literal slug (`'Competencia "no-existe" no encontrada'`) — friendlier sibling 404s for /penca/g/ and /penca/me/share/ say `'Este link de penca no funciona o ya venció.'`. Unify to the friendlier message.
- **member-invited:** OG image left-side circle is empty white. Fill with group initial in Archivo Black, or a soccer-ball glyph, or remove the circle.
- **member-invited:** Topbar "Compartir" link on /penca/g/ is identical for owner and non-owner. Rename to "Compartir la penca" or hide for non-owners (they have their own /me/share/).

---

## What works well (don't regress)

1. **Multi-penca auto-routing is flawless.** `2-1` after tapping a match writes to ALL 3 pencas in a single op, overwrite keeps the same prediction post IDs (no duplicates), reply lists every penca with WhatsApp asterisks in creation order: `'✅ Anotado en *X*, *Y*, *Z*: Bolívar 2 - 1 Cerro Porteño'`. This is the killer feature and it works.
2. **Cold join via /sumate/ pre-armed message is a one-message join.** Bot recognises `'Hola, me quiero sumar a Familia 2026 · FAMILIA2026G2GYH'` and joins in ONE reply — no name/phone/code prompts, sets penca active, auto-seeds 10 predictions, sends roster + links. Ideal flow.
3. **Slim match detail panel is the right shape.** 3 lines (matchup / kickoff·phase / current prediction + ask), no `En: <penca>` preamble. Protect from regression.
4. **Token separation works where it's wired.** `view_token ≠ share_token` on /me/share/, `/penca/me/share/<view_token>/` correctly 404s, public group page exposes no per-user tokens/phones. (Bot reply path is the gap — see blocker #1.)
5. **Competition pages are clean.** Exactly 2 pills (Libertadores 2026 + Libertadores semanal), no Mundial/Sudamericana/LigaUY/Esta-semana orphans, phase + matchday hoisted per day in Spanish, all team/country names in Spanish.
6. **Themed 404 is hard-routed across all /penca/* paths.** No Lorem-ipsum Assembler fallthrough on /penca/no-existe/, /penca/g/bogus/, /penca/me/share/bogus/. Hard-routed 404 = good. (Only the recovery CTA text is stale — blocker #3.)
7. **Performance is excellent.** Home + competition pages: DOMContentLoaded ~740ms, transferSize ~48KB. Bot deterministic replies <50ms (tabla=10ms, partidos=26ms, mis-pencas=6ms). PWA install banner is mobile-only with localStorage dismissal persistence.
8. **OG/preview tags + image are production-grade.** og:title/og:description/og:image/twitter:card/og:locale all present on `/sumate/`, OG image is a real 1200×630 PNG with Mantia branding. WhatsApp link previews will look professional.

---

## Recommended ordering

Phased by file so each PR is small and targeted.

### Phase 1 — One-file copy sweep in `class-mantia-whatsapp-flow.php` (1-2 hours, single PR)
Search-and-replace pass + the silent-fail bare-score fix. Most paper-cuts and 2 of 5 blockers collapse into one PR here.
1. `mundialistas` → `de fútbol` (lines 1261 + 1379) — closes blocker #3 for the bot surface.
2. `Uruguay 2 Portugal 1` → `Boca 2 River 1` in help example.
3. `mis grupos` → `mis pencas` in help/home suggestion text (regex already accepts both, only displayed text changes).
4. `Proximos` / `Despues` / `Todavia` / `pronostico` / `cargar pronostico` → add tildes.
5. `Re-enviar` button label → `Compartir`.
6. `format_kickoff`: swap `gmdate('D j M • H:i', …)` → `wp_date('D j M • H:i', …)` so days are Spanish.
7. Bare-score-without-pending-match handler: return a picker reply ("No identifiqué el partido — tocá uno ↓") OR auto-target the next un-predicted match. Never return empty string. **Closes blocker #2.**
8. While here: confirm `handle_score_pending` has a `kickoff_utc < now` guard. If not, add it. **Closes blocker #4.**

### Phase 2 — Share/security PR (2-3 hours)
1. **In `class-mantia-whatsapp-flow.php`**: `handle_share_link` and the penca-creation confirmation builder — emit `share_token` URLs, not `view_token`. Drop "Tu link privado" line. Backfill `share_token` lazily on first share for groups with empty share_token. **Closes blocker #1.**
2. **In CPT registrations** (`includes/class-mantia-cpts.php` or equivalent): set `show_in_rest => false` on `mantia_prediction`, `mantia_user`, `mantia_group`, `mantia_match`. **Closes blocker #5.**
3. **In home template** (`template-home.php` / mantia-home shortcode): `Ver el ranking del Mundial` → `Ver Libertadores 2026`. **Closes part of blocker #3.**
4. **In themed 404 template** (`class-mantia-404.php`): `Ver Mundial 2026` → `Ver Libertadores 2026`. **Closes remainder of blocker #3.**

### Phase 3 — Visible quality polish (1-2 days, separate PRs ok)
1. List rows in `mis pencas` — always return Interactive List, append `· N jugadores · M pronósticos` to description (Group 5).
2. /penca/g/<token>/ empty-state — render roster or pedestal placeholders when no scores exist (Group 6).
3. Web share-poster wa.me — include specific share URL in text and footer (Group 7).
4. PWA canonical-path rewrite for `/service-worker.js` (Group 8).
5. Profile and cache `handle_share_link` to bring 465ms down to <50ms (Group 9).
6. Remaining polish items: `Hola!` tone, active-penca emoji consistency, switch-to-detail-in-one-tap, /sumate/ auto-redirect, en-dash → arrow on competition subtitle, 404 H1 unification, OG image circle fill, topbar Compartir rename.

After Phase 1 + 2 land, Mantia is shippable. Phase 3 is the "feels finished" tail.
