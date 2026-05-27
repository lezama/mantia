# Mantia UX · Expert Review
_senior designer audit · 2026-05-27T09:27:28_

## libe·alice — expert audit (creator with predictions)

Grading passed

## libe·bob — expert audit (joiner with one prediction)

The page demonstrates strong mobile-first UX fundamentals with clear visual hierarchy, accessible tap targets, and Spanish-language microcopy. However, there are notable gaps in member agency, system feedback, and progressive disclosure that would frustrate a returning user like Bob.

**Strengths:**
- Identity clarity: Bob is marked as 'vos' in the roster (✓ 0-3 prediction visible)
- Primary action prominence: 'Editar mis pronósticos' is the first CTA, lime-green, 54px tall (exceeds 44px minimum)
- Conversion funnel: Clear path from group view → edit predictions → invite friends
- Microcopy quality: Action verbs are direct ('Editar', 'Invitar', 'Sumate')
- Visual hierarchy: Hero section (group name, player count, status) → roster → matches → scoring rules
- Accessibility: Semantic structure (main, section, h1), color contrast on lime bg is AA-compliant per CSS comment
- Trust signals: Wordmark, group metadata (3 players, fecha 1), scoring rules visible
- Offline-first: Service worker registration + localStorage for PWA dismissal

**Critical gaps:**
1. **Member agency is opaque.** Bob can edit predictions or chat the bot, but cannot:
   - View his own match history or points (no /me link visible)
   - See if he's earned points from his 0-3 prediction
   - Leave the group or switch to P_MUN_MTX (no group switcher)
   - Understand why the leaderboard shows 'Sin puntos todavía' when he predicted

2. **System status is incomplete.** The page says 'fecha 1 · jornada en curso' but:
   - Doesn't clarify if matches have kicked off yet (affects prediction lock)
   - Doesn't show Bob's current points or rank (only roster names)
   - Doesn't explain why his prediction (0-3) is visible but no score is shown

3. **Progressive disclosure fails.** The roster is shown pre-leaderboard, which is good for new members, but:
   - No hint that tapping a player name shows their history
   - No 'View full leaderboard' link once scores exist
   - No indication that Bob can see Alice's predictions (privacy model unclear)

4. **Error recovery is absent.** If Bob's prediction fails to save:
   - No error message visible on this view
   - No retry affordance
   - No offline fallback hint

5. **Microcopy ambiguity:**
   - 'próximos partidos' — unclear if these are locked or editable
   - 'cómo se puntúa' — scoring rules are shown but no link to Bob's earned points
   - 'o pronosticar por chat con el bot' — doesn't explain when to use bot vs. web form

6. **Mobile thumb zone underutilized.** The topbar buttons (back, share) are at the top; the primary CTA is below the fold on short screens. A sticky footer with the edit button would reduce scrolling.

7. **Accessibility gaps:**
   - Roster rows lack semantic buttons/links (divs, not clickable)
   - Match rows are divs, not links (no href)
   - No skip-to-content link
   - Avatar initials lack aria-label (only aria-hidden)

8. **Conversion friction.** To edit predictions, Bob must:
   - Tap 'Editar mis pronósticos' → navigate to /me/ → find match → edit → save
   - No inline edit on this view (compare: Slack's inline reactions)
   - No quick-add buttons (e.g., 'Predecir 1-0' chips) visible until edit page

**Verdict:** The page is **visually cohesive and mobile-optimized** but **underserves returning members** like Bob. It's designed for group discovery (new members joining via invite) rather than ongoing engagement (checking points, switching groups, managing predictions). For a WhatsApp-native product where users expect frictionless, bot-like interactions, the web view feels like a secondary dashboard rather than a first-class citizen.

## libe·anon — expert audit (anonymous visitor)

The page demonstrates strong mobile-first UX fundamentals with clear conversion funnel design, but has several friction points and trust/clarity gaps that could cause bounce from anonymous visitors. Strengths: prominent WhatsApp CTA (44px+ tap target, high-contrast magenta), semantic structure, Spanish microcopy, responsive layout. Critical issues: (1) Value proposition buried—'P_LIBE_MTX' headline is cryptic without context (no 'what is this?' explainer before join); (2) Join flow requires external WhatsApp deeplink with pre-filled code, creating friction vs. in-app join; (3) 'Tabla del grupo' section shows roster (Alice/Bob/Carol names visible) which violates stated privacy requirement—anonymous visitors should not see member names before joining; (4) 'Sin puntos todavía' messaging is confusing (why show empty state if no matches have kicked off?); (5) No trust signals (no 'created by Alice', no timestamp, no 'X people joined this week'); (6) Scoring rules buried below fold—should be above join CTA; (7) 'Próximos partidos' section is 6 matches with no filtering/pagination—cognitive overload on mobile; (8) Two competing CTAs at bottom ('compartí' + 'creá tu propia') dilute focus from the primary join action. The page is *functionally* sound (no broken links, good contrast, proper semantic HTML) but the *conversion funnel* is leaky: an anonymous visitor sees a cryptic group name, a roster of strangers, and a WhatsApp deeplink—not a clear 'join this fun game' story.

## libe-comp·alice — expert audit (competition page personalized)

The page prioritizes competition marketing (hero section with 'Libertadores fecha 6' headline, chip nav, global context) before Alice's personal pencas. For a returning member with active predictions, this ranking is inverted. The 'mis pencas' section appears after the hero + nav, buried below the fold on mobile. The conversion funnel breaks: Alice lands, sees marketing copy, then has to scroll to find her group. The primary CTA ('Crear tu propia penca') at the bottom is for *new* groups, not for managing her existing one. A returning user should see (1) her groups first, (2) her predictions in context, (3) then discovery of new matches. The page treats her as a cold visitor, not a member. Secondary issues: (a) 'mis pencas de Libertadores fecha 6' eyebrow is lowercase + soft-colored, reducing scannability; (b) the group card (P_LIBE_MTX) lacks a primary action — it's a link but no button affordance signals 'tap to enter'; (c) match rows show only 2/6 predictions made, but no visual urgency or progress indicator; (d) the bottom CTA invites group creation instead of offering 'Ver leaderboard' or 'Editar pronósticos' for her existing group. Trust + clarity suffer: Alice can't immediately confirm she's in the right place or what her next action should be.

## libe-comp·anon — expert audit (competition page anonymous)

The page demonstrates strong mobile-first UX fundamentals with clear visual hierarchy, appropriate tap targets, and effective conversion funnel design, but has notable gaps in social proof presentation and accessibility that undermine its landing page effectiveness.

STRENGTHS:
• Hero section (eyebrow + h1 + meta) clearly communicates the competition in <3 seconds: "Libertadores fecha 6 (prueba)" with date/match count
• Dual CTA pattern is unambiguous: "Sumate a una con código" (secondary pill) vs "Crear tu propia penca" (primary magenta pill) — clear distinction via color + position
• Tap targets meet 44px minimum (pills are 54px tall, topbar buttons 42px)
• Typography hierarchy is strong: Archivo Black display font for h1 (38px), body text (16px) readable
• Color contrast passes AA on lime background (#c5f24e): text is #0a0a0a or #3d3d39
• Semantic HTML structure is clean (main, section, nav, footer)
• Responsive grid layout (max-width: 560px) appropriate for mobile-first

CRITICAL GAPS:
1. SOCIAL PROOF BURIED: The activity hint ("🔥 1 grupo ya está jugando · 3 jugador entre todos") is visually weak — it's a plain .mantia-activity-hint card with no visual distinction from match rows. For an anonymous visitor deciding whether to join, this is the strongest trust signal on the page, yet it's positioned AFTER the match list and styled as a secondary info block. Should be elevated to hero or immediately post-hero with stronger visual treatment (e.g., badge, highlight color, emoji prominence).

2. EMPTY STATE MESSAGING: "Todavía no hay puntos — la tabla aparece cuando se resuelve el primer partido" is accurate but passive. Doesn't reassure a first-time visitor that joining is safe/normal. Missing: "Todos empiezan en 0 puntos" or similar.

3. MICROCOPY FRICTION: "¿Te invitaron a uno? Mandale el código al bot por WhatsApp" assumes the user already knows what a "código" is and how WhatsApp integration works. For cold traffic, this is jargon. Should be: "¿Ya tenés un código de invitación?" + clearer next step.

4. MISSING ALT TEXT: The SVG back arrow in topbar has aria-label="Inicio" but the icon itself has aria-hidden="true" — correct. However, no images have alt attributes (QR card, avatars if present). Not critical on this page but a pattern risk.

5. ACTIVITY HINT GRAMMAR: "3 jugador entre todos" should be "3 jugadores" (plural). Minor but undermines trust in a landing page.

6. CONVERSION FUNNEL CLARITY: The page doesn't explain what happens after clicking "Crear tu propia penca." Does it open WhatsApp? Does it copy a message? The href is wa.me/... so it will deeplink, but a first-time user may not expect that. A one-liner like "(abre WhatsApp)" would reduce friction.

7. VISUAL HIERARCHY MISS: The match list (6 rows) dominates the page visually. For an anonymous visitor who hasn't predicted yet, this is secondary content. The CTAs should feel more prominent — they're currently below the fold on many phones.

MODERATE ISSUES:
• No loading state or skeleton for the leaderboard ("Todavía no hay puntos") — if this is a real async fetch, users on slow connections won't see feedback
• Chips nav ("Libertadores fecha 6" vs "Mundial 2026") is good, but the active chip styling (dark bg + accent shadow) is subtle — could be bolder
• No error boundary or offline fallback visible in the HTML

ACCESSIBILITY:
• Semantic structure is solid (main, section, nav, footer)
• Color contrast is AA-compliant
• No ARIA misuse detected
• Missing: lang="es" on <html> (it's there, good)
• Missing: descriptive link text on topbar back button (aria-label is present, acceptable)

OVERALL ASSESSMENT:
This is a well-crafted mobile page with strong fundamentals, but it's optimized for *existing users* (leaderboard, match list) rather than *cold traffic* (anonymous visitors). The social proof is present but buried; the CTAs are clear but not prominent enough; the microcopy assumes familiarity. For a landing page, it should lead with "Why join?" (activity hint) before "How to join?" (CTAs).

The page would convert better if:
1. Activity hint moved to hero section with stronger styling
2. Grammar fix ("jugadores")
3. CTA section moved higher (before match list)
4. Microcopy clarified ("código" → "código de invitación"; add "(abre WhatsApp)" hint)
5. Empty state reframed as reassurance ("Todos empiezan en 0 puntos")

## mun·bob — expert audit (NON-member viewing)

The page handles the edge case of an identified non-member visitor with reasonable clarity but has several UX friction points and a privacy concern. Strengths: the activity line ('Ya tenés cuenta — pero todavía no estás en esta penca') correctly signals the situation; the primary CTA is frictionless (WhatsApp deeplink); the roster shows members without exposing scores. Weaknesses: (1) The CTA copy is awkwardly long and buries the action verb ('Sumate también') behind the group name + code — should be 'Sumate a P_MUN_MTX' with code as secondary label. (2) No visual distinction between 'join this group' and 'create your own' CTAs at the bottom — both are ghost links, same weight. (3) The activity line uses informal Spanish ('Ya tenés') which is correct for Argentina/Uruguay but may alienate other Spanish-speaking regions; 'Ya tenés una cuenta' is also slightly awkward — 'Tenés cuenta pero no estás en esta penca' would be clearer. (4) The roster shows member names (Alice, Carol) without scores, which is good privacy-wise, but the empty leaderboard message ('Sin puntos todavía') creates cognitive dissonance — why show a roster if there's no table? This could confuse whether the user is 'in' the group or just viewing it. (5) Accessibility: the activity line is plain text in a <p> tag with no semantic role — should be an alert or callout to ensure screen readers announce it prominently. (6) Mobile tap targets: the 'compartí cómo va la penca' and 'creá tu propia penca' links at the bottom are 13.5px font on a ghost link (no padding) — likely <44px tall, violating Apple HIG. (7) Conversion funnel: after the user taps 'Sumate', they leave the web entirely (WhatsApp deeplink) — no confirmation, no return path. If the WhatsApp bot fails or the user cancels, they're stranded. (8) Trust signal: the page correctly shows the group creator is absent (no 'Alice' badge or 'created by' label), which is honest, but the lack of any 'this is a real group' signal (e.g., 'created 2 days ago', member count confidence) makes it feel slightly ephemeral.

