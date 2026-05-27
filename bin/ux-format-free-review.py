#!/usr/bin/env python3
"""Format the free-review promptfoo output into a readable markdown report.

The freereview rubric asks the LLM to emit a small JSON object per page
(first_impression, what_works, what_seems_off, would_a_user_be_confused,
verdict). This script extracts that JSON and renders it.
"""
import json
import re
import sys
import datetime

if len(sys.argv) < 2:
    sys.exit("usage: ux-format-free-review.py <promptfoo-output.json>")

with open(sys.argv[1]) as f:
    data = json.load(f)

results = (
    data.get("results", {}).get("results")
    or data.get("evalRecord", {}).get("results")
    or []
)

print(f"# Mantia UX · Free Review")
print(f"_open-ended LLM judgment · {datetime.datetime.now().isoformat(timespec='seconds')}_")
print()


def extract_json(text: str) -> dict | None:
    """Pull the first JSON object out of the LLM's reasoning. Tolerant
    of code fences, trailing prose, etc."""
    text = text.strip()
    text = re.sub(r"^```(?:json)?\s*", "", text)
    text = re.sub(r"```\s*$", "", text)
    # Try direct parse first
    try:
        return json.loads(text)
    except Exception:
        pass
    # Fall back to first {...} block
    m = re.search(r"\{.*\}", text, re.S)
    if not m:
        return None
    try:
        return json.loads(m.group(0))
    except Exception:
        return None


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
        parsed = extract_json(reason)

        print(f"## {desc}")
        print()
        if parsed is None:
            # Plain prose fallback
            print(reason)
            print()
            continue

        if parsed.get("first_impression"):
            print(f"> {parsed['first_impression']}")
            print()

        works = parsed.get("what_works") or []
        if works:
            print("**What works:**")
            for w in works:
                print(f"- {w}")
            print()

        off = parsed.get("what_seems_off") or []
        if off:
            print("**What seems off:**")
            for o in off:
                print(f"- {o}")
            print()

        conf = parsed.get("would_a_user_be_confused")
        verd = parsed.get("verdict")
        if conf or verd:
            row = []
            if conf:
                row.append(f"confused: **{conf}**")
            if verd:
                row.append(f"verdict: **{verd}**")
            print(" · ".join(row))
            print()
