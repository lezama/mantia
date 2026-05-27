#!/usr/bin/env python3
"""Format the expert-review promptfoo output into a readable report.

Each rubric returns a structured JSON with page_role, primary_action,
strengths, issues (with severity + principle + suggestion), overall_grade,
and would_release.
"""
import json
import re
import sys
import datetime

if len(sys.argv) < 2:
    sys.exit("usage: ux-format-expert-review.py <promptfoo-output.json>")

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


SEV_ICON = {"blocker": "🔴", "polish": "🟡", "nit": "🟢"}
GRADE_ICON = {"A": "🌟", "B": "✅", "C": "⚠️", "D": "❌", "F": "❌"}

print("# Mantia UX · Expert Review")
print(f"_senior designer audit · {datetime.datetime.now().isoformat(timespec='seconds')}_")
print()

all_issues = []

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
        data = extract_json(reason)

        print(f"## {desc}")
        print()

        if not data:
            print(reason)
            print()
            continue

        grade = data.get("overall_grade", "?")
        rel = data.get("would_release", "?")
        print(f"**Grade:** {GRADE_ICON.get(grade, '·')} {grade} · **Release:** {rel}")
        print()
        if data.get("page_role"):
            print(f"_Page role:_ {data['page_role']}")
            print()
        if data.get("primary_action"):
            print(f"_Primary action:_ {data['primary_action']}")
            print()

        strengths = data.get("strengths") or []
        if strengths:
            print("**Strengths:**")
            for s in strengths:
                obs = s.get("observation", "?") if isinstance(s, dict) else str(s)
                prin = s.get("principle", "") if isinstance(s, dict) else ""
                print(f"- {obs}" + (f" _({prin})_" if prin else ""))
            print()

        issues = data.get("issues") or []
        if issues:
            print("**Issues:**")
            for i in issues:
                if not isinstance(i, dict):
                    print(f"- {i}")
                    continue
                sev = i.get("severity", "nit")
                icon = SEV_ICON.get(sev, "·")
                obs = i.get("observation", "?")
                prin = i.get("principle", "?")
                sug = i.get("suggestion", "")
                print(f"- {icon} **{sev}** — {obs}")
                print(f"  - _principle:_ {prin}")
                if sug:
                    print(f"  - _suggestion:_ {sug}")
                all_issues.append({**i, "page": desc})
            print()

# Aggregate the cross-page punch list of actionable issues
if all_issues:
    print("---")
    print()
    print("## Aggregated punch list (by severity)")
    print()
    for sev in ("blocker", "polish", "nit"):
        bucket = [i for i in all_issues if i.get("severity") == sev]
        if not bucket:
            continue
        print(f"### {SEV_ICON.get(sev, '·')} {sev} ({len(bucket)})")
        print()
        for i in bucket:
            print(f"- **{i.get('page', '?')}** — {i.get('observation', '?')}")
            if i.get("suggestion"):
                print(f"  - {i['suggestion']}")
        print()
