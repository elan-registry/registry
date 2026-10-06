#!/usr/bin/env python3
"""Build index.html: every summary page, newest first, plus each series' regenerate prompt.

Pages are named summary-<slug>-YYYY-MM-DD.html. A series' prompt is prompts/<slug>.txt.
Run after any summary page is added or deleted. Output stays in docs/plans/summaries/
(build output, gitignored) even though this script lives in scripts/ (source, tracked).
"""
import html
import re
from pathlib import Path

HERE = Path(__file__).resolve().parent.parent / "docs" / "plans" / "summaries"
PAGE_RE = re.compile(r"^summary-(?P<slug>.+)-(?P<date>\d{4}-\d{2}-\d{2})\.html$")


def page_meta(path):
    text = path.read_text(encoding="utf-8")
    title = re.search(r"<title>(.*?)</title>", text, re.S)
    lede = re.search(r'<p class="lede">(.*?)</p>', text, re.S)
    return (
        html.unescape(title.group(1).strip()) if title else path.stem,
        html.unescape(re.sub(r"<[^>]+>", "", lede.group(1)).strip()) if lede else "",
    )


pages = []
for f in HERE.glob("summary-*.html"):
    m = PAGE_RE.match(f.name)
    if m:
        title, lede = page_meta(f)
        pages.append({"file": f.name, "slug": m["slug"], "date": m["date"], "title": title, "lede": lede})
# Newest first; the file name breaks ties so the order is stable.
pages.sort(key=lambda p: (p["date"], p["file"]), reverse=True)

prompts = {f.stem: f.read_text(encoding="utf-8").rstrip() for f in sorted((HERE / "prompts").glob("*.txt"))}
series_title = {p["slug"]: p["title"] for p in reversed(pages)}  # newest title wins

rows = "\n".join(
    f'''    <li><a href="{html.escape(p["file"])}">
      <time>{p["date"]}</time>
      <span><b>{html.escape(p["title"])}</b><small>{html.escape(p["lede"])}</small></span>
      <em>{html.escape(p["slug"])}</em>
    </a></li>'''
    for p in pages
) or '    <li class="empty">No summary pages yet.</li>'

blocks = "\n".join(
    f'''  <div class="prompt">
    <h3>{html.escape(series_title.get(slug, slug))} <em>prompts/{html.escape(slug)}.txt</em></h3>
    <div class="prompt-wrap">
      <button type="button" class="copy">Copy</button>
<pre>{html.escape(text)}</pre>
    </div>
  </div>'''
    for slug, text in prompts.items()
) or '  <p class="cap">No prompts yet.</p>'

out = f"""<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Summaries</title>
<style>
  :root {{
    --bg: #FAF9F7; --ink: #333333; --muted: #6B6B6B; --line: #E5E2DE; --raised: #F2EFEB; --accent: #00563F;
    --sans: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Noto Sans", "Liberation Sans", Helvetica, Arial, sans-serif;
    --mono: ui-monospace, "SF Mono", "Cascadia Code", "Roboto Mono", "DejaVu Sans Mono", Menlo, Consolas, monospace;
  }}
  * {{ box-sizing: border-box; }}
  body {{ margin: 0; background: var(--bg); color: var(--ink); font-family: var(--sans); font-size: 16px; line-height: 1.55; }}
  main {{ max-width: 820px; margin: 0 auto; padding: 48px 16px 72px; }}
  h1 {{ font-size: 34px; line-height: 1.15; margin: 0 0 8px; }}
  .lede, .cap {{ color: var(--muted); margin: 0; max-width: 65ch; }}
  section {{ margin-top: 56px; }}
  h2 {{ font-size: 13px; letter-spacing: .08em; text-transform: uppercase; color: var(--muted); margin: 0 0 16px; font-weight: 600; }}
  ol {{ list-style: none; margin: 0; padding: 0; }}
  ol a {{ display: grid; grid-template-columns: 7.5em 1fr auto; gap: 16px; align-items: baseline; padding: 14px 0; border-top: 1px solid var(--line); color: inherit; text-decoration: none; }}
  ol a:hover b {{ color: var(--accent); text-decoration: underline; }}
  ol li:first-child a {{ border-top: 0; }}
  time, em {{ font-family: var(--mono); font-size: 13px; font-style: normal; color: var(--muted); }}
  b {{ display: block; font-size: 17px; }}
  small {{ display: block; font-size: 14px; color: var(--muted); }}
  .empty {{ color: var(--muted); }}
  .prompt + .prompt {{ margin-top: 32px; }}
  h3 {{ font-size: 16px; margin: 0 0 10px; display: flex; gap: 12px; align-items: baseline; flex-wrap: wrap; }}
  .prompt-wrap {{ position: relative; }}
  pre {{ background: var(--raised); border-radius: 10px; padding: 16px; margin: 0; font-family: var(--mono); font-size: 13px; line-height: 1.55; white-space: pre-wrap; overflow-wrap: anywhere; }}
  .copy {{ position: absolute; top: 10px; right: 10px; font: 600 13px var(--sans); color: var(--accent); background: var(--bg); border: 1.5px solid var(--accent); border-radius: 6px; padding: 4px 12px; cursor: pointer; }}
  @media (max-width: 600px) {{ ol a {{ grid-template-columns: 1fr; gap: 2px; }} h1 {{ font-size: 28px; }} }}
  @media print {{ .copy {{ display: none; }} }}
</style>
</head>
<body>
<main>
  <h1>Summaries</h1>
  <p class="lede">Status pages in docs/plans/summaries, newest first. Built by scripts/build-summary-index.py.</p>

<section>
  <h2>Pages · {len(pages)}</h2>
  <ol>
{rows}
  </ol>
</section>

<section>
  <h2>Regenerate prompts</h2>
  <p class="cap" style="margin-bottom:20px">Paste into Claude Code from Registry/. Edit the .txt file to change a prompt.</p>
{blocks}
</section>
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
(HERE / "index.html").write_text(out, encoding="utf-8")
print(f"index.html: {len(pages)} page(s), {len(prompts)} prompt(s)")
