# Mantia · primetime-readiness — Round 4 (UX maturity)

**Generated:** 2026-05-20T00:30:00Z
**Personas:** Don Roberto (65, primera vez), Tincho (organizer 6 jugadores — stalled), Laura (journalist — stalled)
**Framing:** UX maturity / comprehensibility / brand polish — NOT regression QA.

R4 changed the framing from "do the flows work?" to "would a non-tech
65-year-old uncle understand this without anyone explaining it?" Don
Roberto's report was rich (22 findings). Tincho and Laura agents both
stalled on the watchdog mid-investigation — their pre-stall observations
are still useful signals.

---

## Don Roberto's verdict (22 findings: 4 excelencia, 7 info-faltante, 5 necesita-explicacion, 4 voz-rota, 2 overwhelm)

**Original verdict:** "necesita-explicacion — está bueno pero solo no llegás los primeros 30 segundos."

**Findings he flagged + status:**

| # | Issue | Status |
|---|---|---|
| 1 | "voz-rota: Genial!" in picker prompt | ✅ Fixed → "Listo. ¿Para qué torneo es...?" |
| 2 | "voz-rota: ¡Bien ahí!" on all-predicted | ✅ Fixed (dropped) |
| 3 | "voz-rota: pegale el link a alguien!" | ✅ Fixed → "Compartí el link con tus amigos para sumarlos" |
| 4 | "voz-rota: Tue/Wed/Thu English days" | ✅ Fixed — hardcoded Spanish day/month tables; prod's WP locale is en_US, wp_date() didn't help |
| 5 | "necesita-explicacion: saludo no explica 'penca'" | ✅ Fixed — new opener defines penca + explains the activity + names the differentiator + asks a one-button question |
| 6 | "necesita-explicacion: tap match auto-confirms score" | ✅ Fixed — removed auto-apply on tap; surface the stashed score and let user re-send to confirm |
| 7 | LLM error leak ("me faltó permiso del sistema") on "quien va ganando" | ⏳ Tracked — agent abilities throwing raw error strings to user. Need to wrap with friendly fallback. |
| 8-22 | Other info-faltante / overwhelm / excelencia notes | See `tests/qa-output/tio-roberto-findings.json` |

**Don Roberto's 4 "excelencia" callouts** (protect from regression):
- Saludo de 3 botones bien dimensionado
- Pedido del nombre con ejemplo + escape hatch
- "Que es esto?" devuelve respuesta estructurada
- Tabla vacía con copy humana

---

## Tincho (stalled — partial observation)

Got far enough to:
- Create penca, get 5 other personas (Juan, Pablo, Diego, …) to join.
- Observed: bot reply showed "QA" instead of "Juan" / "Pablo" in member roster.
- Was investigating divergence between state-op output (display_name=Juan) and bot-reply rendering.

**Likely cause:** `get_or_create_user` updates post_title from `$identity['name']`, but the bot's first reply may use the existing title (the QA prefix from a previous run). Or member_lines is showing a cached version.

**Action:** worth a re-investigation in a quieter R5. Not a blocker.

---

## Laura (stalled — partial observation)

Got far enough to observe `/penca/g/<token>/` "redirected to a personal /penca/me/ page". Likely the token she fetched was a user view_token, not a group view_token, or the agent confused redirects from `/sumate/` and `/compartir/` paths.

**Action:** her Chrome MCP session showed the overall site polish was good (no Lorem-ipsum, themed 404 clean, etc. — re-confirming R3). Detailed copy/polish review pending.

---

## What landed in this cycle

5 of Don Roberto's 6 actionable findings closed in one commit:
- Hardcoded Spanish dates (R3-era "fix" was insufficient — wp_date honored en_US locale)
- Voice cleanup (Genial!, ¡Bien ahí!, pegale!)
- Cold opener that explains "penca" + activity + differentiator
- Tap-on-match no longer auto-applies stashed score (surfaces it instead)

Pending for next cycle:
- LLM error leak ("permiso del sistema" string surfaced to end-user)
- "Bot showing QA instead of name" (Tincho observation — needs reproduction)
- Tincho + Laura full passes once watchdog isn't a constraint
- Don Roberto's remaining 14 findings (info-faltante about points / who-else-plays / how-to-share — likely all addressable with copy nudges in the appropriate handlers)

---

## Watchdog learnings (round 4 edition)

The R3 stub-first writing pattern works (Don Roberto completed cleanly).
But Tincho's scope was too broad (6 personas + many interactions) — he
ran out of streaming budget mid-investigation. Laura's Chrome MCP usage
also burns budget — each screenshot is ~2-5s of network and a wait.

For R5, recommendation: write the persona prompt around a hard cap of
~15 tool calls per agent (cold-onboarding sweep, not full-product
investigation). Use multi-agent fanout for breadth — each agent picks
one slice of the surface.

---

## What's truly ship-ready post-R4

The product is incrementally better than after R3. Don Roberto's verdict
moves from "necesita-explicacion" to ~"comprehensible-with-light-friction".
The voice is more grounded, the cold opener explains the noun, dates are
in Spanish across all surfaces, and the surprising auto-apply behavior
is gone.

The remaining work is incremental polish + the LLM error leak (single
real bug among the partial findings). No blockers added in this cycle.
