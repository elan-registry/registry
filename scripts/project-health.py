#!/usr/bin/env python3
"""Write a project health snapshot for the summaries index.

Reads GitHub with the gh CLI and writes
docs/plans/summaries/health/<YYYY-MM-DD>.json. scripts/build-summary-index.py
shows the newest snapshot at the top of the index and uses older ones for a
trend line. Run it before the index builder, by hand or on a schedule.

Measures:
- velocity: issues closed and PRs merged per week, last --weeks weeks
- open issues: count, average and median age in days, oldest
- issue mix: open issues, and issues closed in the last 30 days, split into
  defects, features, security, maintenance and other. A title prefix
  (bug:/fix:, feat:, security:, chore:/tech-debt:/test:/refactor:/docs:/perf:)
  decides first; labels decide only when the title has no known prefix
- lead time: median days from open to close for issues closed in 30 days
- the current milestone: the open milestone with the lowest version that has
  open issues, and its closed/total count

Usage: scripts/project-health.py [--dir PATH] [--weeks N] [--keep N]
  --dir PATH  summaries folder (default: docs/plans/summaries in this repo)
  --weeks N   weeks of velocity (default 8)
  --keep N    snapshots to keep (default 90; 0 keeps all)

Exit codes: 0 written; 1 usage error or missing folder; 2 a gh call failed or
returned JSON of an unexpected shape (nothing written). SUMMARY_TODAY=YYYY-MM-DD
overrides today's date (tests).
"""
import argparse
import datetime as dt
import json
import os
import re
import statistics
import subprocess
import sys
from pathlib import Path

REPO = "elan-registry/registry"
DEFAULT_DIR = Path(__file__).resolve().parent.parent / "docs" / "plans" / "summaries"


class GhError(Exception):
    pass


class UsageParser(argparse.ArgumentParser):
    """argparse exits 2 on a usage error; here 2 means a gh failure."""

    def error(self, message):
        self.print_usage(sys.stderr)
        self.exit(1, f"{self.prog}: error: {message}\n")


def expect(data, kind, what):
    """Raise GhError when gh returns JSON of the wrong shape. A silent
    default here would write a snapshot of zeros and exit 0."""
    names = {dict: "object", list: "array", str: "string", int: "number",
             float: "number", bool: "boolean", type(None): "null"}
    if not isinstance(data, kind):
        raise GhError(f"{what}: expected a JSON {names[kind]}, got {names.get(type(data), type(data).__name__)}")
    return data


def gh_json(args):
    """Run gh and parse its JSON stdout. Use --paginate with --slurp: without
    it gh may print one JSON array per page, which is not one JSON value."""
    try:
        out = subprocess.run(["gh"] + args, capture_output=True, text=True, check=False)
    except OSError as exc:
        raise GhError(f"could not run gh: {exc}") from exc
    if out.returncode != 0:
        raise GhError(f"gh {' '.join(args[:2])} failed: {out.stderr.strip()}")
    text = out.stdout.strip()
    if not text:
        return []
    return json.loads(text)


def search(query, **params):
    """One search/issues page. A timed-out search returns partial results
    with incomplete_results set; low counts would look like real data."""
    args = ["api", "-X", "GET", "search/issues", "-f", f"q=repo:{REPO} {query}"]
    for key, value in params.items():
        args += ["-f", f"{key}={value}"]
    data = expect(gh_json(args), dict, "search")
    if data.get("incomplete_results"):
        raise GhError(f"search timed out with incomplete results: {query}")
    return data


def search_count(query):
    data = search(query, per_page=1)
    if not isinstance(data.get("total_count"), int):
        raise GhError("search count: no total_count in the response")
    return data["total_count"]


def search_items(query):
    items, page = [], 1
    while True:
        batch = expect(search(query, per_page=100, page=page).get("items"), list, "search items")
        items.extend(batch)
        if len(batch) < 100 or page >= 10:
            return items
        page += 1


KINDS = ("defects", "features", "security", "maintenance", "other")
TITLE_KINDS = (
    (("bug", "fix"), "defects"),
    (("feat",), "features"),
    (("security",), "security"),
    (("chore", "tech-debt", "test", "refactor", "docs", "perf"), "maintenance"),
)
LABEL_KINDS = (
    ({"bug", "signal:defect"}, "defects"),
    ({"enhancement", "feature"}, "features"),
    ({"component: security"}, "security"),
    ({"tech-debt", "refactor", "dependencies"}, "maintenance"),
)


def kind(issue):
    """The title's conventional-commit prefix wins: it is the author's own
    call. Labels decide only when the title has no known prefix."""
    m = re.match(r"([a-z-]+)(?:\([^)]*\))?:", issue.get("title", "").lower())
    if m:
        for prefixes, name in TITLE_KINDS:
            if m.group(1) in prefixes:
                return name
    labels = {lbl.get("name", "").lower() for lbl in issue.get("labels", [])}
    for wanted, name in LABEL_KINDS:
        if labels & wanted:
            return name
    return "other"


def day(stamp):
    return dt.date.fromisoformat(stamp[:10])


def version_key(title):
    m = re.match(r"v(\d+)\.(\d+)\.(\d+)(?:\.(\d+))?", title)
    return tuple(int(x or 0) for x in m.groups()) if m else None


def snapshot(today, weeks):
    velocity = []
    for i in range(weeks, 0, -1):
        start = today - dt.timedelta(days=7 * i)
        end = start + dt.timedelta(days=6)
        rng = f"{start.isoformat()}..{end.isoformat()}"
        velocity.append({
            "week_start": start.isoformat(),
            "issues_closed": search_count(f"is:issue is:closed closed:{rng}"),
            "prs_merged": search_count(f"is:pr is:merged merged:{rng}"),
        })

    pages = expect(gh_json(["api", "--paginate", "--slurp", f"repos/{REPO}/issues?state=open&per_page=100"]),
                   list, "open issues")
    open_issues = [i for page in pages for i in expect(page, list, "open issues page")
                   if "pull_request" not in i]
    ages = [(today - day(i["created_at"])).days for i in open_issues]
    open_kinds = dict.fromkeys(KINDS, 0)
    for i in open_issues:
        open_kinds[kind(i)] += 1

    since = (today - dt.timedelta(days=30)).isoformat()
    closed = search_items(f"is:issue is:closed reason:completed closed:>={since}")
    closed_kinds = dict.fromkeys(KINDS, 0)
    for i in closed:
        closed_kinds[kind(i)] += 1
    leads = [(day(i["closed_at"]) - day(i["created_at"])).days for i in closed if i.get("closed_at")]

    milestones = expect(gh_json(["api", f"repos/{REPO}/milestones?state=open&per_page=100"]), list, "milestones")
    current = None
    for m in sorted((m for m in milestones if version_key(m.get("title", ""))),
                    key=lambda m: version_key(m["title"])):
        if m.get("open_issues", 0) > 0:
            current = {"title": m["title"], "closed": m.get("closed_issues", 0),
                       "total": m.get("closed_issues", 0) + m.get("open_issues", 0)}
            break

    return {
        "generated": today.isoformat(),
        "velocity": velocity,
        "open": {
            "total": len(open_issues),
            "avg_age_days": round(sum(ages) / len(ages), 1) if ages else 0,
            "median_age_days": statistics.median(ages) if ages else 0,
            "oldest_days": max(ages) if ages else 0,
            **open_kinds,
        },
        "closed_30d": {
            "total": len(closed),
            **closed_kinds,
            "median_lead_days": statistics.median(leads) if leads else None,
        },
        "milestone": current,
    }


def main(argv):
    p = UsageParser()
    p.add_argument("--dir", type=Path, default=DEFAULT_DIR)
    p.add_argument("--weeks", type=int, default=8)
    p.add_argument("--keep", type=int, default=90)
    a = p.parse_args(argv)
    if a.weeks < 1 or a.keep < 0:
        print("project-health.py: --weeks must be 1 or more, --keep 0 or more", file=sys.stderr)
        return 1
    if not a.dir.is_dir():
        print(f"project-health.py: no such folder: {a.dir}", file=sys.stderr)
        return 1
    forced = os.environ.get("SUMMARY_TODAY")
    today = dt.date.fromisoformat(forced) if forced else dt.date.today()
    try:
        data = snapshot(today, a.weeks)
    except (GhError, ValueError, KeyError, TypeError, AttributeError) as exc:
        # TypeError/AttributeError: gh returned JSON of an unexpected shape.
        print(f"project-health.py: {exc}. Nothing written.", file=sys.stderr)
        return 2
    out_dir = a.dir / "health"
    out_dir.mkdir(exist_ok=True)
    path = out_dir / f"{today.isoformat()}.json"
    path.write_text(json.dumps(data, indent=2) + "\n", encoding="utf-8")
    snaps = sorted(out_dir.glob("????-??-??.json"), reverse=True)
    if a.keep:
        for old in snaps[a.keep:]:
            old.unlink()
    print(f"wrote {path.relative_to(a.dir)}: {data['open']['total']} open, "
          f"{sum(w['issues_closed'] for w in data['velocity'])} issues closed in {a.weeks} weeks")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
