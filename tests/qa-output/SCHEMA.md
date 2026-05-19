# findings.json schema

Each persona-agent writes one file: `tests/qa-output/<persona-slug>-findings.json`.

```jsonc
{
  "persona": "organizer-cold",
  "ran_at": "2026-05-19T18:42:00Z",
  "flows_run": ["onboarding-cold", "crear-penca", "pronostico-text"],
  "findings": [
    {
      "severity": "blocker",       // blocker | paper-cut | polish | works
      "lens": "copy",              // copy | ia | visual | flow | security | performance
      "flow": "onboarding-cold",
      "where": "primer reply al hola",
      "what": "Bot menciona 'pronósticos mundialistas' pero Mundial fue eliminado",
      "fix_hint": "Buscar 'mundialistas' en class-mantia-whatsapp-flow.php — cambiar a 'pronósticos de fútbol' (genérico)",
      "evidence": "reply: 'Soy Mantia, la app de pronósticos mundialistas por WhatsApp.'"
    }
  ],
  "screenshots": [
    "/tmp/qa-XXXXX/01-home-mobile.png",
    "/tmp/qa-XXXXX/02-onboarding-reply.txt"
  ]
}
```

## Severity definitions

- **blocker** — would prevent shipping. Bot returns nothing, 5xx, broken
  flow, security leak.
- **paper-cut** — visible quality issue. Stale copy, truncation, broken
  pluralization, untranslated text. Multiple of these add up to "feels
  unfinished".
- **polish** — refinement opportunity. Spacing, animation, hover state.
- **works** — flagged positively. Things that work especially well, useful
  to call out so they don't regress.

## Lens definitions

- **copy** — text quality, voice, voseo, tone.
- **ia** — information architecture, picker contents, navigation.
- **visual** — layout, truncation, spacing, color.
- **flow** — multi-step interaction, state consistency, error recovery.
- **security** — token leaks, unauth access, prompt injection.
- **performance** — latency, page-weight, render-time.

## Rules for agents writing findings

1. **No hallucinations.** Every finding cites `evidence` (a literal reply,
   a screenshot path, or a DB state observation).
2. **Be specific.** "Looks bad" is not a finding. "Row 3 of the picker
   shows 'Esta semana' which was removed in migration v4" is.
3. **Suggest the fix when obvious.** Not every finding needs a code-level
   suggestion, but blockers should always.
4. **Distinguish what works.** Findings of severity `works` are valuable —
   they protect things from regression.
