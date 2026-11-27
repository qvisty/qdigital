"""Read-only SEO checks for the public sitemap. Python standard library only.

Observations come in three levels:
  critical     the page can be indexed wrongly or not at all
  recommended  a clear improvement, e.g. a missing meta description
  info         worth knowing, not an error, e.g. several H1 headings

Nothing here fails the job, so the audit never blocks deployment.
"""
from html.parser import HTMLParser
from pathlib import Path
from urllib.parse import urlsplit, urljoin, unquote
from collections import Counter
import xml.etree.ElementTree as ET
import os

ROOT = Path(__file__).resolve().parents[1]
BASE = "https://qdigital.dk/"
NS = {"s": "http://www.sitemaps.org/schemas/sitemap/0.9"}
LEVELS = ("critical", "recommended", "info")
issues = {level: [] for level in LEVELS}

def warn(page, message, level="critical"):
    issues[level].append(f"{page}: {message}")

class Page(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.title = ""
        self.in_title = False
        self.h1 = []          # language of each H1, from the nearest lang attribute
        self.lang = [("", "", 0)]  # stack of (tag, lang, nesting of that tag)
        self.canon = []
        self.description = ""
        self.robots = ""
        self.links = []
    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        top = self.lang[-1]
        if a.get("lang"):
            self.lang.append((tag, a["lang"].lower(), 1))
        elif tag == top[0]:
            self.lang[-1] = (top[0], top[1], top[2] + 1)
        if tag == "title":
            self.in_title = True
        elif tag == "h1":
            self.h1.append(self.lang[-1][1])
        elif tag == "link" and "canonical" in (a.get("rel") or "").lower().split():
            self.canon.append(a.get("href") or "")
        elif tag == "meta":
            name = (a.get("name") or "").lower()
            if name == "description":
                self.description = a.get("content") or ""
            elif name in ("robots", "googlebot"):
                self.robots += " " + (a.get("content") or "").lower()
        elif tag == "a" and a.get("href"):
            self.links.append(a["href"])
    def handle_endtag(self, tag):
        if tag == "title":
            self.in_title = False
        top = self.lang[-1]
        if len(self.lang) > 1 and tag == top[0]:
            if top[2] > 1:
                self.lang[-1] = (top[0], top[1], top[2] - 1)
            else:
                self.lang.pop()
    def handle_data(self, data):
        if self.in_title:
            self.title += data

def local_path(url):
    p = unquote(urlsplit(url).path)
    path = (ROOT / p.lstrip("/")).resolve()
    if not path.is_relative_to(ROOT):
        return None
    if path.is_dir() or p.endswith("/"):
        for name in ("index.html", "index.php"):
            if (path / name).is_file():
                return path / name
        return None
    return path if path.is_file() else None

def main():
    urls = [x.text.strip() for x in ET.parse(ROOT / "sitemap.xml").findall("s:url/s:loc", NS) if x.text]
    if not urls:
        warn("sitemap.xml", "No URLs found")
    for url, count in Counter(urls).items():
        if count > 1:
            warn(url, "Duplicate sitemap entry")
    robots = (ROOT / "robots.txt").read_text(encoding="utf-8")
    if not any(line.strip().lower() == "sitemap: https://qdigital.dk/sitemap.xml" for line in robots.splitlines()):
        warn("robots.txt", "Missing expected sitemap reference")
    titles = {}
    for url in dict.fromkeys(urls):
        parsed = urlsplit(url)
        if parsed.scheme != "https" or parsed.netloc != "qdigital.dk" or parsed.query or parsed.fragment:
            warn(url, "Unexpected sitemap URL")
            continue
        path = local_path(url)
        if not path:
            warn(url, "No matching local file")
            continue
        if path.suffix != ".html":
            warn(url, "Dynamic page: manual SEO check required", "info")
            continue
        page = Page()
        page.feed(path.read_text(encoding="utf-8"))
        title = page.title.strip()
        if not title:
            warn(url, "Missing title")
        elif title in titles:
            warn(url, "Duplicate title also used by " + titles[title], "recommended")
        else:
            titles[title] = url
        if not page.description.strip():
            warn(url, "Missing meta description", "recommended")
        if page.canon != [url]:
            warn(url, "Expected exactly one self-referencing canonical")
        if "noindex" in page.robots or "none" in page.robots.split():
            warn(url, "Sitemap page has noindex directive")
        if not page.h1:
            warn(url, "Missing H1")
        elif len(page.h1) != len(set(page.h1)):
            # One H1 per language section, marked with lang, is a legitimate bilingual page.
            warn(url, f"{len(page.h1)} H1 headings in the same language", "info")
        for href in set(page.links):
            target = urlsplit(urljoin(url, href))
            if target.scheme in ("http", "https") and target.hostname in ("qdigital.dk", "www.qdigital.dk"):
                if not local_path(target.geturl()):
                    warn(url, "Potential broken internal link: " + href)
    counts = ", ".join(f"{len(set(issues[level]))} {level}" for level in LEVELS)
    report = [f"Checked {len(urls)} sitemap URLs: {counts}."]
    for level in LEVELS:
        for issue in sorted(set(issues[level])):
            report.append(f"[{level}] {issue}")
    print("\n".join(report))
    if os.environ.get("GITHUB_STEP_SUMMARY"):
        with open(os.environ["GITHUB_STEP_SUMMARY"], "a", encoding="utf-8") as f:
            f.write("# Q Digital SEO audit\n\n" + report[0] + "\n")
            for level in LEVELS:
                found = sorted(set(issues[level]))
                if found:
                    f.write(f"\n## {level.capitalize()}\n\n")
                    for issue in found:
                        f.write("- " + issue.replace("<", "&lt;").replace(">", "&gt;") + "\n")
    # Advisory findings intentionally do not fail deployment.

if __name__ == "__main__":
    main()
