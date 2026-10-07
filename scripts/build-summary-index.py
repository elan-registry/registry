#!/usr/bin/env python3
"""Build docs/plans/summaries/index.html from one folder per summary series.

Layout (docs/plans/ is gitignored; this script is tracked):

    docs/plans/summaries/
      index.html                 built by this script
      <series>/
        prompt.md                front matter, then the regenerate prompt
        <YYYY-MM-DD>.html        one page per run

prompt.md front matter, between two `---` lines:

    title: Verification System Status
    category: Status           (groups the index; Status, Health, Process,
                                Codebase, Review come first, in that order)
    refresh_days: 7            (older than this = stale)

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
import os
import re
import sys
from pathlib import Path

DEFAULT_DIR = Path(__file__).resolve().parent.parent / "docs" / "plans" / "summaries"
PAGE_RE = re.compile(r"^(?P<date>\d{4}-\d{2}-\d{2})\.html$")
CATEGORY_ORDER = ["Status", "Health", "Process", "Codebase", "Review"]


def parse_args(argv):
    p = argparse.ArgumentParser(add_help=True)
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


def page_meta(path):
    text = path.read_text(encoding="utf-8")
    title = re.search(r"<title>(.*?)</title>", text, re.S)
    lede = re.search(r'<p class="lede">(.*?)</p>', text, re.S)
    return (
        html.unescape(title.group(1).strip()) if title else "",
        html.unescape(re.sub(r"\s+", " ", re.sub(r"<[^>]+>", "", lede.group(1))).strip()) if lede else "",
    )


def today():
    forced = os.environ.get("SUMMARY_TODAY")
    return dt.date.fromisoformat(forced) if forced else dt.date.today()


def collect(root, keep):
    series, deleted = [], []
    for d in sorted(p for p in root.iterdir() if p.is_dir()):
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


def render(series, root_name):
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
  @media (max-width: 600px) {{ h1 {{ font-size: 28px; }} .row {{ flex-wrap: wrap; }} }}
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
{body}
  <p class="cap" style="margin-top:40px">To regenerate a series, open its prompt, copy it, and paste it into Claude Code from Registry/. Edit the series' prompt.md to change it.</p>
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
    for name in deleted:
        print(f"deleted {name} (keep {a.keep})")
    for loose in sorted(root.glob("*.html")):
        if loose.name != "index.html":
            print(f"warning: {loose.name} is not in a series folder and is not indexed", file=sys.stderr)
    (root / "index.html").write_text(render(series, "docs/plans/summaries"), encoding="utf-8")
    stale = sum(1 for s in series if s["stale"])
    print(f"index.html: {len(series)} series, {sum(len(s['pages']) for s in series)} page(s), {stale} stale")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
