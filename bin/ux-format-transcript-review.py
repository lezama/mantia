#!/usr/bin/env python3
"""Format the transcript-review promptfoo output into a readable
markdown report.

The transcript reviewer rubric emits JSON like:
  {
    "scene":    "joiner-onboarding",
    "verdict":  "PASS" | "FAIL",
    "blockers": [{"rule": "R1", "observation": "..."}],
    "polish":   [{"rule": "P1", "observation": "...", "suggestion": "..."}]
  }
This formatter walks every test result, extracts the JSON, and emits a
grouped markdown doc with the punch list at the bottom.
"""
import datetime
import json
import re
import sys

if len(sys.argv) < 2:
    sys.exit("usage: ux-format-transcript-review.py <promptfoo-output.json>")

with open(sys.argv[1]) as f:
    data = json.load(f)

results = (
    data.get("results", {}).get("results")
    or data.get("evalRecord", {}).get("results")
    or []
)


def extract_json(text: str):
    if not text:
        return None
    text = text.strip()
    text = re.sub(r"^```(?:json)?\s*", "", text)
    text = re.sub(r"```\s*$", "", text)
    try:
        return json.loads(text)
    except Exception:
        pass
    m = re.search(r"\{.*\}", text, re.S)
    if not m:
        return None
    try:
        return json.loads(m.group(0))
    except Exception:
        return None


VERDICT_ICON = {"PASS": "✅", "FAIL": "❌"}

print("# Mantia · transcript review")
print(
    f"_LLM-rubric stakeholder-sim audit · "
    f"{datetime.datetime.now().isoformat(timespec='seconds')}_"
)
print()

all_blockers = []
all_polish = []

for r in results:
    desc = (
        r.get("description")
        or r.get("testCase", {}).get("description")
        or "(unnamed)"
    )
    gr = r.get("gradingResult", {})
    crs = gr.get("componentResults", []) or [gr]
    for cr in crs:
        reason = (cr.get("reason") or "").strip()
        if not reason:
            continue
        data_blob = extract_json(reason)

        print(f"## {desc}")
        print()

        if not data_blob:
            print(reason)
            print()
            continue

        verdict = data_blob.get("verdict", "?")
        icon = VERDICT_ICON.get(verdict, "·")
        print(f"**Verdict:** {icon} {verdict}")
        print()

        blockers = data_blob.get("blockers") or []
        if blockers:
            print("**🔴 Blockers:**")
            for b in blockers:
                if not isinstance(b, dict):
                    print(f"- {b}")
                    continue
                rule = b.get("rule", "?")
                obs = b.get("observation", "?")
                print(f"- **{rule}** — {obs}")
                all_blockers.append({**b, "scene": desc})
            print()

        polish = data_blob.get("polish") or []
        if polish:
            print("**🟡 Polish:**")
            for p in polish:
                if not isinstance(p, dict):
                    print(f"- {p}")
                    continue
                rule = p.get("rule", "?")
                obs = p.get("observation", "?")
                sug = p.get("suggestion", "")
                print(f"- **{rule}** — {obs}")
                if sug:
                    print(f"  - _suggestion:_ {sug}")
                all_polish.append({**p, "scene": desc})
            print()

if all_blockers or all_polish:
    print("---")
    print()
    print("## Aggregated punch list")
    print()
    if all_blockers:
        print(f"### 🔴 Blockers ({len(all_blockers)})")
        print()
        for b in all_blockers:
            print(f"- **{b.get('scene', '?')}** · {b.get('rule', '?')} — {b.get('observation', '?')}")
        print()
    if all_polish:
        print(f"### 🟡 Polish ({len(all_polish)})")
        print()
        for p in all_polish:
            line = f"- **{p.get('scene', '?')}** · {p.get('rule', '?')} — {p.get('observation', '?')}"
            print(line)
            if p.get("suggestion"):
                print(f"  - {p['suggestion']}")
        print()
