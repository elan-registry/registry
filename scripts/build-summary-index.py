#!/usr/bin/env python3
"""Build docs/plans/summaries/index.html from one folder per summary series.

Layout (docs/plans/ is gitignored; this script is tracked):

    docs/plans/summaries/
      index.html                 built by this script
      <series>/
        prompt.md                front matter, then the regenerate prompt
        <YYYY-MM-DD>.html        one page per run
      health/<YYYY-MM-DD>.json   snapshots from scripts/project-health.py

prompt.md front matter, between two `---` lines:

    title: Verification System Status
    category: Status           (groups the index; Status, Health, Process,
                                Codebase, Review come first, in that order)
    refresh_days: 7            (older than this = stale)

The index opens with a Project health section drawn from the newest health
snapshot (velocity, open-issue age, issue mix, current milestone) and, when
older snapshots exist, the open-issue trend. With no snapshot the section
says how to make one. This script makes no network calls.

The index groups series by category. Each series shows its newest page with
a fresh/stale badge, its older pages, and its prompt (collapsed, with a Copy
button and a link to prompt.md). The builder keeps the newest --keep pages
per series (default 3) and deletes older ones.

Usage: scripts/build-summary-index.py [--dir PATH] [--keep N]
  --dir PATH  summaries folder (default: docs/plans/summaries in this repo)
  --keep N    pages to keep per series; 0 keeps every page (default 3)

Exit codes: 0 built; 1 usage error or the folder does not exist.
SUMMARY_TODAY=YYYY-MM-DD overrides today's date (for tests).
"""
import argparse
import datetime as dt
import html
import json
import os
import re
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parent.parent
DEFAULT_DIR = REPO_ROOT / "docs" / "plans" / "summaries"
LAUNCHD_LABEL = "org.elanregistry.summary-health"
PAGE_RE = re.compile(r"^(?P<date>\d{4}-\d{2}-\d{2})\.html$")
CATEGORY_ORDER = ["Status", "Health", "Process", "Codebase", "Review"]
HEALTH_DIR = "health"
HEALTH_STALE_DAYS = 7
KIND_STYLE = [  # (key, label, colour) in stack order
    ("defects", "defects", "#B3261E"),
    ("security", "security", "#A15C07"),
    ("features", "features", "#00563F"),
    ("maintenance", "maintenance", "#5B6B8C"),
    ("other", "other", "#B8B2AA"),
]


class UsageParser(argparse.ArgumentParser):
    """argparse exits 2 on a usage error; this script documents exit 1."""

    def error(self, message):
        self.print_usage(sys.stderr)
        self.exit(1, f"{self.prog}: error: {message}\n")


def parse_args(argv):
    p = UsageParser(add_help=True)
    p.add_argument("--dir", type=Path, default=DEFAULT_DIR)
    p.add_argument("--keep", type=int, default=3)
    a = p.parse_args(argv)
    if a.keep < 0:
        p.error("--keep must be 0 or more")
    return a


def read_prompt(path):
    """Return (front matter dict, prompt body) from prompt.md."""
    if not path.is_file():
        return {}, ""
    text = path.read_text(encoding="utf-8")
    meta = {}
    m = re.match(r"^---\n(.*?)\n---\n?", text, re.S)
    if m:
        for line in m.group(1).splitlines():
            if ":" in line:
                k, v = line.split(":", 1)
                meta[k.strip()] = v.strip()
        text = text[m.end():]
    return meta, text.strip()


def _text(fragment):
    return html.unescape(re.sub(r"\s+", " ", re.sub(r"<[^>]+>", "", fragment))).strip()


def page_meta(path):
    """Return (title, one-line summary) for a page.

    The summary is the first of: <p class="lede">, <meta name="description">,
    or a .verdict-chip plus .tagline pair (older pages use those).
    """
    text = path.read_text(encoding="utf-8")
    title = re.search(r"<title>(.*?)</title>", text, re.S)
    lede = re.search(r'<p class="lede">(.*?)</p>', text, re.S)
    meta = re.search(r'<meta\s+name="description"\s+content="([^"]*)"', text)
    chip = re.search(r'class="verdict-chip">(.*?)</', text, re.S)
    tag = re.search(r'class="tagline">(.*?)</', text, re.S)
    if lede:
        summary = _text(lede.group(1))
    elif meta:
        summary = _text(meta.group(1))
    elif chip or tag:
        summary = ". ".join(_text(m.group(1)) for m in (chip, tag) if m)
    else:
        summary = ""
    return (_text(title.group(1)) if title else "", summary)


def today():
    forced = os.environ.get("SUMMARY_TODAY")
    return dt.date.fromisoformat(forced) if forced else dt.date.today()


def collect(root, keep):
    series, deleted = [], []
    for d in sorted(p for p in root.iterdir() if p.is_dir() and p.name != HEALTH_DIR):
        pages = sorted((f for f in d.iterdir() if PAGE_RE.match(f.name)), key=lambda f: f.name, reverse=True)
        meta, prompt = read_prompt(d / "prompt.md")
        if not pages and not prompt:
            continue
        if keep and len(pages) > keep:
            for old in pages[keep:]:
                old.unlink()
                deleted.append(f"{d.name}/{old.name}")
            pages = pages[:keep]
        title, lede = page_meta(pages[0]) if pages else ("", "")
        try:
            refresh = int(meta.get("refresh_days", "0"))
        except ValueError:
            refresh = 0
        latest = dt.date.fromisoformat(pages[0].name[:10]) if pages else None
        age = (today() - latest).days if latest else None
        series.append({
            "slug": d.name,
            "title": meta.get("title") or title or d.name,
            "category": meta.get("category") or "Other",
            "refresh": refresh,
            "lede": lede,
            "pages": [f.name for f in pages],
            "age": age,
            "stale": (age is None) or (refresh > 0 and age > refresh),
            "prompt": prompt,
            "has_prompt": (d / "prompt.md").is_file(),
        })
    return series, deleted


def category_key(name):
    return (CATEGORY_ORDER.index(name), name) if name in CATEGORY_ORDER else (len(CATEGORY_ORDER), name)


def age_text(s):
    if s["age"] is None:
        return "no page yet"
    if s["age"] == 0:
        return "today"
    return "1 day ago" if s["age"] == 1 else f"{s['age']} days ago"


def load_health(root):
    """Return the health snapshots, newest first. A file that is not a JSON
    object is skipped with a warning, so one bad file cannot break the index."""
    snaps = []
    folder = root / HEALTH_DIR
    if not folder.is_dir():
        return snaps
    for f in sorted(folder.glob("????-??-??.json"), reverse=True):
        try:
            data = json.loads(f.read_text(encoding="utf-8"))
            if not isinstance(data, dict):
                raise ValueError("not a JSON object")
            data["_date"] = dt.date.fromisoformat(f.name[:10])
            snaps.append(data)
        except (ValueError, OSError) as exc:
            print(f"warning: {HEALTH_DIR}/{f.name} skipped: {exc}", file=sys.stderr)
    return snaps


def _num(value, digits=0):
    if value is None:
        return "—"
    return f"{value:.{digits}f}" if digits else f"{round(value)}"


def velocity_svg(weeks):
    """Paired bars per week: issues closed (accent) and PRs merged (muted)."""
    e = html.escape
    if not weeks:
        return ""
    top = max(max(w.get("issues_closed", 0), w.get("prs_merged", 0)) for w in weeks) or 1
    pad_l, pad_t, plot_h, col = 8, 22, 120, 76
    width = pad_l * 2 + col * len(weeks)
    height = pad_t + plot_h + 40
    bw = 24
    parts = [f'<svg class="chart" viewBox="0 0 {width} {height}" role="img" '
             f'aria-label="Issues closed and PRs merged per week">']
    parts.append(f'<line x1="{pad_l}" y1="{pad_t + plot_h}" x2="{width - pad_l}" y2="{pad_t + plot_h}" stroke="#E5E2DE"/>')
    for i, w in enumerate(weeks):
        x0 = pad_l + i * col + (col - 2 * bw - 4) / 2
        for j, (key, colour) in enumerate((("issues_closed", "#00563F"), ("prs_merged", "#B8B2AA"))):
            v = w.get(key, 0)
            h = plot_h * v / top
            x = x0 + j * (bw + 4)
            y = pad_t + plot_h - h
            parts.append(f'<rect x="{x:.1f}" y="{y:.1f}" width="{bw}" height="{h:.1f}" rx="2" fill="{colour}"/>')
            parts.append(f'<text x="{x + bw / 2:.1f}" y="{y - 5:.1f}" class="v">{v}</text>')
        label = dt.date.fromisoformat(w["week_start"]).strftime("%b %-d")
        parts.append(f'<text x="{pad_l + i * col + col / 2:.1f}" y="{pad_t + plot_h + 18}" class="x">{e(label)}</text>')
    parts.append("</svg>")
    return "".join(parts)


def mix_bar(label, counts):
    """One stacked bar for an issue mix. Counts go in the legend, not on the
    bar, so a thin segment can never hide its own number."""
    e = html.escape
    total = sum(counts.get(k, 0) for k, _, _ in KIND_STYLE)
    segs = "".join(
        f'<span style="flex:{counts.get(k, 0)};background:{c}" title="{e(name)}: {counts.get(k, 0)}"></span>'
        for k, name, c in KIND_STYLE if counts.get(k, 0)
    )
    legend = "".join(
        f'<li><i style="background:{c}"></i>{e(name)} <b>{counts.get(k, 0)}</b></li>'
        for k, name, c in KIND_STYLE
    )
    return (f'<div class="mix"><div class="mix-head"><span>{e(label)}</span><b>{total}</b></div>'
            f'<div class="stack">{segs or "<span style=flex:1></span>"}</div><ul>{legend}</ul></div>')


def trend_svg(snaps):
    """Open issues across snapshots, oldest to newest. Needs two or more."""
    pts = [(s["_date"], s.get("open", {}).get("total")) for s in reversed(snaps)]
    pts = [(d, v) for d, v in pts if isinstance(v, (int, float))]
    if len(pts) < 2:
        return ""
    lo, hi = min(v for _, v in pts), max(v for _, v in pts)
    span = (hi - lo) or 1
    w, h, pad = 560, 90, 18
    step = (w - 2 * pad) / (len(pts) - 1)
    xy = [(pad + i * step, pad + (h - 2 * pad) * (1 - (v - lo) / span)) for i, (_, v) in enumerate(pts)]
    path = " ".join(f"{x:.1f},{y:.1f}" for x, y in xy)
    first, last = pts[0], pts[-1]
    return (f'<svg class="chart" viewBox="0 0 {w} {h + 22}" role="img" aria-label="Open issues over time">'
            f'<polyline points="{path}" fill="none" stroke="#00563F" stroke-width="2"/>'
            f'<circle cx="{xy[-1][0]:.1f}" cy="{xy[-1][1]:.1f}" r="4" fill="#00563F"/>'
            f'<text x="{pad}" y="{h + 16}" class="x" text-anchor="start">{first[0].isoformat()} · {first[1]}</text>'
            f'<text x="{w - pad}" y="{h + 16}" class="x" text-anchor="end">{last[0].isoformat()} · {last[1]}</text>'
            f"</svg>")


def render_health(snaps):
    e = html.escape
    if not snaps:
        return ('  <section class="health">\n    <h2>Project health</h2>\n'
                '    <p class="cap">No snapshot yet. Run <code>scripts/project-health.py</code>, '
                'then this builder again.</p>\n  </section>')
    s = snaps[0]
    age = (today() - s["_date"]).days
    stale = age > HEALTH_STALE_DAYS
    op = s.get("open", {})
    cl = s.get("closed_30d", {})
    vel = s.get("velocity", [])
    n_weeks = len(vel)
    avg_closed = sum(w.get("issues_closed", 0) for w in vel) / n_weeks if n_weeks else None
    avg_prs = sum(w.get("prs_merged", 0) for w in vel) / n_weeks if n_weeks else None
    ms = s.get("milestone")
    ms_html = ""
    if ms and ms.get("total"):
        pct = 100 * ms.get("closed", 0) / ms["total"]
        ms_html = (f'<div class="ms"><div class="mix-head"><span>{e(ms.get("title", ""))}</span>'
                   f'<b>{ms.get("closed", 0)}/{ms["total"]}</b></div>'
                   f'<div class="stack"><span style="flex:{pct:.1f};background:#00563F"></span>'
                   f'<span style="flex:{100 - pct:.1f};background:#E5E2DE"></span></div></div>')
    defect_share = (100 * op.get("defects", 0) / op["total"]) if op.get("total") else None
    trend = trend_svg(snaps)
    trend_html = f'<h3>Open issues over time</h3>{trend}' if trend else ""
    badge = (f'<span class="badge stale">{age} days old</span>' if stale
             else '<span class="badge fresh">current</span>')
    return f'''  <section class="health">
    <div class="row"><h2>Project health</h2>{badge}</div>
    <div class="glance">
      <div><b>{_num(avg_closed)}</b><span>issues closed / week</span></div>
      <div><b>{_num(avg_prs)}</b><span>PRs merged / week</span></div>
      <div><b>{op.get("total", "—")}</b><span>open issues</span></div>
      <div><b>{_num(op.get("median_age_days"))}<small>d</small></b><span>median open age (avg {_num(op.get("avg_age_days"))}d)</span></div>
      <div><b>{_num(cl.get("median_lead_days"))}<small>d</small></b><span>median open→close, 30 days</span></div>
      <div class="{'warn' if defect_share and defect_share >= 40 else ''}"><b>{_num(defect_share)}<small>%</small></b><span>of open issues are defects</span></div>
    </div>
    <h3>Velocity, last {n_weeks} weeks <span class="key"><i style="background:#00563F"></i>issues closed <i style="background:#B8B2AA"></i>PRs merged</span></h3>
    {velocity_svg(vel)}
    <h3>Issue mix</h3>
    {mix_bar("open now", op)}
    {mix_bar("closed, last 30 days", cl)}
    {('<h3>Current milestone</h3>' + ms_html) if ms_html else ""}
    {trend_html}
    <p class="cap src">Snapshot {e(s["_date"].isoformat())} from GitHub. Refresh: <code>scripts/project-health.py &amp;&amp; scripts/build-summary-index.py</code></p>
  </section>'''


def flow_svg():
    """How the index is built: two lanes (health snapshot, summary pages)
    that meet at the builder. Columns come from one grid, so boxes and
    arrows cannot overlap."""
    e = html.escape
    bw, bh, gap, pad = 140, 50, 22, 10
    lane_y = (20, 110)
    col_x = [pad + i * (bw + gap) for i in range(5)]
    width = col_x[-1] + bw + pad
    height = lane_y[1] + bh + 20
    out = [f'<svg class="chart flow" viewBox="0 0 {width} {height}" role="img" '
           f'aria-label="How the summaries index is built">',
           '<defs><marker id="arr" viewBox="0 0 8 8" refX="7" refY="4" markerWidth="7" markerHeight="7" orient="auto">'
           '<path d="M0,0 L8,4 L0,8 z" fill="#6B6B6B"/></marker></defs>']

    def box(x, y, h, lines, cls=""):
        out.append(f'<rect x="{x}" y="{y}" width="{bw}" height="{h}" rx="8" class="node {cls}"/>')
        first = y + h / 2 - (len(lines) - 1) * 8 + 4
        for i, line in enumerate(lines):
            out.append(f'<text x="{x + bw / 2}" y="{first + i * 16:.1f}">{e(line)}</text>')

    def arrow(x1, y1, x2, y2):
        out.append(f'<line x1="{x1}" y1="{y1}" x2="{x2 - 2}" y2="{y2}" stroke="#6B6B6B" '
                   f'stroke-width="1.5" marker-end="url(#arr)"/>')

    lanes = (
        (["GitHub", "via gh"], ["project-health.py"], ["health/", "<date>.json"]),
        (["<series>/", "prompt.md"], ["Claude Code", "/summary"], ["<series>/", "<date>.html"]),
    )
    for y, cells in zip(lane_y, lanes):
        mid = y + bh / 2
        for c, lines in enumerate(cells):
            box(col_x[c], y, bh, lines)
            arrow(col_x[c] + bw, mid, col_x[c + 1], mid)
    span_h = lane_y[1] + bh - lane_y[0]
    box(col_x[3], lane_y[0], span_h, ["build-summary-", "index.py"], "key")
    centre = lane_y[0] + span_h / 2
    box(col_x[4], centre - bh / 2, bh, ["index.html"], "key")
    arrow(col_x[3] + bw, centre, col_x[4], centre)
    out.append("</svg>")
    return "".join(out)


def launchd_plist(repo):
    """A user LaunchAgent that refreshes the health snapshot and the index
    every morning. A login shell (zsh -l) gives it the PATH where gh and
    python3 live; launchd's own PATH has neither."""
    log = Path.home() / "Library" / "Logs" / "elanregistry-summary-health.log"
    cmd = f"cd '{repo}' && python3 scripts/project-health.py && python3 scripts/build-summary-index.py"
    return f"""<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
  <key>Label</key><string>{LAUNCHD_LABEL}</string>
  <key>ProgramArguments</key>
  <array><string>/bin/zsh</string><string>-lc</string><string>{html.escape(cmd, quote=False)}</string></array>
  <key>StartCalendarInterval</key>
  <dict><key>Hour</key><integer>7</integer><key>Minute</key><integer>0</integer></dict>
  <key>StandardOutPath</key><string>{log}</string>
  <key>StandardErrorPath</key><string>{log}</string>
</dict>
</plist>"""


def render_about(repo):
    e = html.escape
    agent = f"~/Library/LaunchAgents/{LAUNCHD_LABEL}.plist"
    install = (f"# 1. Save the file above as {agent}\n"
               f"launchctl bootstrap gui/$(id -u) {agent}\n"
               f"launchctl kickstart gui/$(id -u)/{LAUNCHD_LABEL}   # run once now\n"
               f"# Remove it:\n"
               f"launchctl bootout gui/$(id -u)/{LAUNCHD_LABEL} && rm {agent}")
    return f'''  <section class="about">
    <h2>How this index is built</h2>
    {flow_svg()}
    <p class="cap">Two inputs meet at the builder. It reads files only and makes no network calls.</p>
    <h3>Refresh</h3>
    <ul class="refresh">
      <li><b>Project health</b><span>run by hand, or daily with the job below</span><code>python3 scripts/project-health.py &amp;&amp; python3 scripts/build-summary-index.py</code></li>
      <li><b>A summary series</b><span>when its badge says stale</span><code>open its prompt, Copy, paste into Claude Code</code></li>
      <li><b>Index only</b><span>after you add or delete a page</span><code>python3 scripts/build-summary-index.py</code></li>
    </ul>
    <p class="cap">Run all commands from {e(str(repo))}. The health badge turns stale after {HEALTH_STALE_DAYS} days without a snapshot.</p>
    <details>
      <summary>Run the health refresh daily (macOS launchd)</summary>
      <p class="cap">Runs at 07:00 when the Mac is awake, or at the next wake. It needs your gh login, so it runs here, not in the cloud.</p>
      <div class="prompt-wrap"><button type="button" class="copy">Copy</button><pre>{e(launchd_plist(repo))}</pre></div>
      <div class="prompt-wrap"><button type="button" class="copy">Copy</button><pre>{e(install)}</pre></div>
    </details>
  </section>'''


def repo_for(root):
    """The checkout that owns a summaries folder. The refresh commands must
    run there, not in whichever worktree ran this builder."""
    root = root.resolve()
    if root.parts[-3:] == ("docs", "plans", "summaries"):
        return root.parents[2]
    return REPO_ROOT


def render(series, root_name, snaps=(), root_dir=DEFAULT_DIR):
    e = html.escape
    cats = {}
    for s in series:
        cats.setdefault(s["category"], []).append(s)
    n_pages = sum(len(s["pages"]) for s in series)
    n_stale = sum(1 for s in series if s["stale"])

    blocks = []
    for cat in sorted(cats, key=category_key):
        cards = []
        for s in sorted(cats[cat], key=lambda s: s["title"].lower()):
            slug = e(s["slug"])
            latest = s["pages"][0] if s["pages"] else None
            badge = (f'<span class="badge stale">stale</span>' if s["stale"]
                     else '<span class="badge fresh">fresh</span>')
            head = (f'<a class="title" href="{slug}/{e(latest)}">{e(s["title"])}</a>' if latest
                    else f'<span class="title">{e(s["title"])}</span>')
            refresh = f'refresh every {s["refresh"]} days' if s["refresh"] else "no refresh set"
            older = "".join(f'<a href="{slug}/{e(p)}">{e(p[:10])}</a>' for p in s["pages"][1:])
            older_html = f'<div class="older"><span>older</span>{older}</div>' if older else ""
            if s["has_prompt"]:
                prompt_html = f'''<details>
        <summary>Regenerate prompt</summary>
        <div class="prompt-wrap"><button type="button" class="copy">Copy</button><pre>{e(s["prompt"])}</pre></div>
        <a class="file" href="{slug}/prompt.md">open {slug}/prompt.md</a>
      </details>'''
            else:
                prompt_html = '<p class="cap">No prompt.md: add one so this series can be regenerated.</p>'
            cards.append(f'''    <article class="card{' is-stale' if s['stale'] else ''}">
      <div class="row">{head}{badge}</div>
      <p class="lede-s">{e(s["lede"])}</p>
      <div class="meta"><time>{e(latest[:10]) if latest else "—"}</time><span>{age_text(s)}</span><span>{refresh}</span></div>
      {older_html}
      {prompt_html}
    </article>''')
        blocks.append(f'''  <section>
    <h2>{e(cat)} <span>{len(cats[cat])}</span></h2>
{chr(10).join(cards)}
  </section>''')

    body = "\n".join(blocks) or '  <p class="cap">No series yet. Add a folder with a prompt.md.</p>'
    return f"""<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Summaries</title>
<style>
  :root {{
    --bg: #FAF9F7; --ink: #333333; --muted: #6B6B6B; --line: #E5E2DE; --raised: #F2EFEB;
    --accent: #00563F; --warn: #A15C07;
    --sans: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Noto Sans", "Liberation Sans", Helvetica, Arial, sans-serif;
    --mono: ui-monospace, "SF Mono", "Cascadia Code", "Roboto Mono", "DejaVu Sans Mono", Menlo, Consolas, monospace;
  }}
  * {{ box-sizing: border-box; }}
  body {{ margin: 0; background: var(--bg); color: var(--ink); font: 16px/1.55 var(--sans); }}
  main {{ max-width: 860px; margin: 0 auto; padding: 48px 16px 72px; }}
  h1 {{ font-size: 34px; line-height: 1.15; margin: 0 0 8px; }}
  .lede, .cap {{ color: var(--muted); margin: 0; max-width: 65ch; }}
  .glance {{ display: flex; gap: 36px; margin: 28px 0 0; flex-wrap: wrap; }}
  .glance div {{ border-top: 3px solid var(--accent); padding-top: 8px; min-width: 110px; }}
  .glance div.warn {{ border-color: var(--warn); }}
  .glance b {{ display: block; font: 600 34px/1 var(--mono); }}
  .glance span {{ color: var(--muted); font-size: 14px; }}
  section {{ margin-top: 48px; }}
  h2 {{ font-size: 13px; letter-spacing: .08em; text-transform: uppercase; color: var(--muted); margin: 0 0 14px; font-weight: 600; }}
  h2 span {{ font-family: var(--mono); margin-left: 6px; }}
  .card {{ border: 1px solid var(--line); border-left: 4px solid var(--accent); border-radius: 10px; padding: 16px 18px; margin: 0 0 14px; background: var(--bg); }}
  .card.is-stale {{ border-left-color: var(--warn); }}
  .row {{ display: flex; gap: 12px; align-items: baseline; justify-content: space-between; }}
  .title {{ font-size: 18px; font-weight: 600; color: inherit; text-decoration: none; }}
  a.title:hover {{ color: var(--accent); text-decoration: underline; }}
  .badge {{ font: 600 12px var(--mono); border-radius: 99px; padding: 2px 10px; white-space: nowrap; }}
  .badge.fresh {{ color: var(--accent); border: 1px solid var(--accent); }}
  .badge.stale {{ color: var(--warn); border: 1px solid var(--warn); }}
  .lede-s {{ margin: 6px 0 8px; font-size: 15px; max-width: 65ch; }}
  .meta {{ display: flex; gap: 18px; flex-wrap: wrap; font: 13px var(--mono); color: var(--muted); }}
  .older {{ margin-top: 8px; display: flex; gap: 12px; flex-wrap: wrap; font: 13px var(--mono); }}
  .older span {{ color: var(--muted); }}
  .older a {{ color: var(--accent); }}
  details {{ margin-top: 12px; }}
  summary {{ cursor: pointer; font-size: 14px; color: var(--accent); font-weight: 600; }}
  .prompt-wrap {{ position: relative; margin-top: 10px; }}
  pre {{ background: var(--raised); border-radius: 10px; padding: 16px; margin: 0; font: 13px/1.55 var(--mono); white-space: pre-wrap; overflow-wrap: anywhere; max-height: 420px; overflow: auto; }}
  .copy {{ position: absolute; top: 10px; right: 14px; font: 600 13px var(--sans); color: var(--accent); background: var(--bg); border: 1.5px solid var(--accent); border-radius: 6px; padding: 4px 12px; cursor: pointer; }}
  .file {{ display: inline-block; margin-top: 8px; font: 13px var(--mono); color: var(--accent); }}
  .health {{ border-top: 1px solid var(--line); padding-top: 28px; }}
  .health .glance {{ display: grid; grid-template-columns: repeat(3, 1fr); margin-top: 12px; gap: 24px 28px; }}
  .health .glance b {{ font-size: 30px; }}
  .health .glance small {{ font-size: 16px; color: var(--muted); margin-left: 2px; }}
  .health .glance span {{ display: block; }}
  h3 {{ font-size: 15px; margin: 32px 0 10px; }}
  .key {{ font: 400 13px var(--sans); color: var(--muted); margin-left: 10px; }}
  .key i, .mix li i {{ display: inline-block; width: 10px; height: 10px; border-radius: 2px; margin: 0 5px 0 10px; }}
  .chart {{ width: 100%; height: auto; display: block; }}
  .chart text {{ font: 12px var(--mono); fill: var(--muted); text-anchor: middle; }}
  .chart text.v {{ fill: var(--ink); }}
  .mix, .ms {{ margin: 0 0 18px; }}
  .mix-head {{ display: flex; justify-content: space-between; font-size: 14px; margin-bottom: 6px; }}
  .mix-head b {{ font-family: var(--mono); }}
  .stack {{ display: flex; height: 16px; border-radius: 4px; overflow: hidden; background: var(--line); gap: 2px; }}
  .mix ul {{ list-style: none; margin: 6px 0 0; padding: 0; display: flex; flex-wrap: wrap; font-size: 13px; color: var(--muted); }}
  .mix li {{ margin-right: 6px; }}
  .mix li:first-child i {{ margin-left: 0; }}
  .mix li b {{ font-family: var(--mono); color: var(--ink); font-weight: 600; }}
  .src {{ margin-top: 20px; font-size: 13px; }}
  code {{ font: 13px var(--mono); background: var(--raised); padding: 1px 5px; border-radius: 4px; }}
  .about {{ border-top: 1px solid var(--line); padding-top: 28px; }}
  .flow {{ margin: 8px 0 10px; }}
  .flow rect.node {{ fill: var(--raised); stroke: var(--line); }}
  .flow rect.key {{ fill: var(--bg); stroke: var(--accent); stroke-width: 1.5; }}
  .flow text {{ fill: var(--ink); font-size: 12px; }}
  .refresh {{ list-style: none; padding: 0; margin: 0 0 12px; }}
  .refresh li {{ border-left: 3px solid var(--accent); padding: 4px 0 4px 12px; margin-bottom: 12px; }}
  .refresh b {{ display: inline-block; min-width: 175px; }}
  .refresh span {{ color: var(--muted); font-size: 14px; }}
  .refresh code {{ display: table; margin-top: 4px; overflow-wrap: anywhere; }}
  .about .prompt-wrap {{ margin-top: 10px; }}
  @media (max-width: 600px) {{ h1 {{ font-size: 28px; }} .row {{ flex-wrap: wrap; }} .health .glance {{ grid-template-columns: repeat(2, 1fr); }} }}
  @media print {{ .copy {{ display: none; }} details {{ display: none; }} }}
</style>
</head>
<body>
<main>
  <h1>Summaries</h1>
  <p class="lede">Visual summary pages in {e(root_name)}, grouped by topic. Built by scripts/build-summary-index.py.</p>
  <div class="glance">
    <div><b>{len(series)}</b><span>series</span></div>
    <div class="{'warn' if n_stale else ''}"><b>{n_stale}</b><span>stale</span></div>
    <div><b>{n_pages}</b><span>pages kept</span></div>
  </div>
{render_health(list(snaps))}
{body}
{render_about(repo_for(root_dir))}
</main>
<script>
  document.querySelectorAll('.copy').forEach(function (btn) {{
    btn.addEventListener('click', function () {{
      var text = btn.parentNode.querySelector('pre').textContent;
      var done = function () {{ btn.textContent = 'Copied'; setTimeout(function () {{ btn.textContent = 'Copy'; }}, 1500); }};
      if (navigator.clipboard) {{ navigator.clipboard.writeText(text).then(done, function () {{}}); }}
    }});
  }});
</script>
</body>
</html>
"""


def main(argv):
    a = parse_args(argv)
    root = a.dir
    if not root.is_dir():
        print(f"build-summary-index.py: no such folder: {root}", file=sys.stderr)
        return 1
    series, deleted = collect(root, a.keep)
    snaps = load_health(root)
    for name in deleted:
        print(f"deleted {name} (keep {a.keep})")
    for loose in sorted(root.glob("*.html")):
        if loose.name != "index.html":
            print(f"warning: {loose.name} is not in a series folder and is not indexed", file=sys.stderr)
    (root / "index.html").write_text(render(series, "docs/plans/summaries", snaps, root), encoding="utf-8")
    stale = sum(1 for s in series if s["stale"])
    health = f"health {snaps[0]['_date'].isoformat()}" if snaps else "no health snapshot"
    print(f"index.html: {len(series)} series, {sum(len(s['pages']) for s in series)} page(s), {stale} stale, {health}")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
