# QA platform

Mantia ships with two layers of testing:

| Layer | Where | What it answers |
|---|---|---|
| **Regression** | `tests/e2e/` + `bin/e2e.sh` | "Does this flow still work the same way?" |
| **Exploratory QA / UX** | `tests/qa-*/` + `bin/qa-*.sh` | "Is this ready for primetime? What's missing, weird, or stale?" |

The regression suite is deterministic — write `assert_contains(...)`, run on every PR,
fail loud on regressions. The QA platform is **agent-driven**: persona agents play real
users, navigate WhatsApp + web end-to-end, and emit structured findings with judgment
calls humans can act on.

## The pieces

```
bin/qa-sim.php       server-side simulator. JSON-in/JSON-out via wp eval-file.
                     Refuses any phone not starting with 9999000 (the QA boundary).
bin/sim-wa.sh        SSH wrapper for one-shot WhatsApp sends.
bin/qa-cleanup.*     wipes all QA-prefixed users / groups / predictions.
bin/qa-dashboard.php aggregates *-findings.json into a self-contained dashboard.html.
bin/qa-run.sh        prep | status | dashboard. Orchestrates the run.

tests/qa-personas/*.md   five personas (organizer-cold, organizer-returning,
                         member-invited, multi-penca, lurker-web-only).
tests/qa-flows/*.md      thirteen flow specs (8 WhatsApp + 5 web).
tests/qa-output/         where agents write findings.json + screenshots.
tests/qa-output/SCHEMA.md the findings contract.
```

## How to run a cycle

```bash
# 1. Deploy + clean any prior QA data on prod
bin/qa-run.sh prep

# 2. From a Claude Code session, spawn the persona agents.
#    Each agent reads its persona + assigned flows and emits a
#    findings.json. Spawn in parallel (single message, N Agent tool
#    calls). Pass them the persona slug; they bootstrap from
#    tests/qa-personas/<slug>.md and the flow files referenced there.

# 3. Once findings.json files exist, run the reviewer agent.
#    (See tests/qa-output/REVIEWER_PROMPT.md for the canonical prompt.)
#    Reviewer reads every *-findings.json, synthesizes by severity,
#    and emits tests/qa-output/primetime-readiness.md.

# 4. Render the dashboard.
bin/qa-run.sh dashboard
open tests/qa-output/dashboard.html
```

## Adding a new persona

1. Drop a new file under `tests/qa-personas/`:
   `06-<slug>.md` with YAML frontmatter (`slug`, `phone` (9999000NN), `name`, `flows`).
2. List the flows they should run.
3. Add a paragraph "Quién es" and "Qué evalúa".
4. Set severity bar.
5. From a Claude Code session, spawn an Agent with the persona's instructions
   (use one of the existing prompts as a template — see commits on the
   `qa-platform` branch).

## Adding a new flow

1. Drop a file under `tests/qa-flows/`:
   `<slug>.md` with frontmatter (`slug`, `title`, `type: whatsapp|web|hybrid`).
2. Steps as a numbered list.
3. Expected outcomes.
4. "Things to evaluate" — qualitative judgment calls the agent must make.
5. Reference the flow in one or more persona files.

## Safety boundary

**Every QA test action uses a phone starting with `9999000`.** The simulator
refuses other phones outright. The cleanup utility re-checks the prefix
before each delete. Groups with any non-QA member are spared. This keeps
QA isolated even on a shared prod DB.

## Cost notes

- One `bin/sim-wa.sh` round-trip ≈ 1-2s (SSH overhead dominates).
- A persona running ~20 turns ≈ 30-40s of real-time work.
- Five persona agents in parallel ≈ ~3 minutes per cycle.
- Each Agent invocation in Claude Code consumes tokens — be mindful when
  running 5+ agents repeatedly. Use `bin/qa-cleanup.sh` between cycles
  so each run starts from a clean state.

## Iterating

Once primetime-readiness.md exists, the workflow is:
1. Read the report (or `dashboard.html` for visual).
2. Fix the blockers one by one (or with a fixer agent — see below).
3. Re-run the agents on the changed code. Compare delta.

The platform is intentionally agent-driven: the **judgment** of what's
"missing" or "ready" is the part deterministic asserts can't capture.
Lean on it.
