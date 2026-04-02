#!/usr/bin/env python3
"""
JobHunter – Local Scraper Helper
=================================
Run this on your own PC. It downloads and parses jobindex.dk search pages
from YOUR internet connection so jobindex.dk never sees the hosted server.

Usage:
    python helper.py          # listens on http://localhost:7474
    python helper.py 8080     # use a different port

The hosted web app connects to this helper automatically.
Change the port in public/js/config.js (LOCAL_SCRAPER_URL) if needed.
"""

import json
import re
import sys
import time
from http.server import HTTPServer, BaseHTTPRequestHandler
from typing import Optional, Tuple, List
from urllib.parse import urljoin

# ── Optional fast libraries, fall back to stdlib ──────────────────────────
try:
    import requests
    from bs4 import BeautifulSoup
    _USE_REQUESTS = True
except ImportError:
    _USE_REQUESTS = False
    import urllib.request

DEFAULT_PORT = 7474

USER_AGENT = (
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
    'AppleWebKit/537.36 (KHTML, like Gecko) '
    'Chrome/124.0.0.0 Safari/537.36'
)
HEADERS = {
    'User-Agent':      USER_AGENT,
    'Accept-Language': 'da,da-DK;q=0.9,en;q=0.8',
    'Accept':          'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
    # Deliberately no Referer so jobindex cannot see where the request came from
}
JOBINDEX_BASE = 'https://www.jobindex.dk'

# ── Scraping ──────────────────────────────────────────────────────────────

def fetch_page(url: str) -> str:
    """Fetch a URL and return the HTML as a string."""
    if _USE_REQUESTS:
        resp = requests.get(url, headers=HEADERS, timeout=20, allow_redirects=True)
        resp.raise_for_status()
        return resp.text
    else:
        req = urllib.request.Request(url, headers=HEADERS)
        with urllib.request.urlopen(req, timeout=20) as r:
            return r.read().decode('utf-8', errors='replace')


def parse_with_bs4(html: str, page_url: str) -> list:
    """Parse jobindex.dk HTML with BeautifulSoup (best quality)."""
    from bs4 import BeautifulSoup
    soup = BeautifulSoup(html, 'html.parser')
    jobs = []

    # Strategy 1: <article> job cards
    articles = soup.find_all('article', class_=re.compile(r'PaidJob|jix_robotjob|job_ad'))
    for art in articles:
        job = extract_from_tag_bs4(art)
        if job:
            jobs.append(job)

    # Strategy 2: any container with a vis-job / jobannonce link
    if not jobs:
        for a in soup.find_all('a', href=True):
            href = a.get('href', '')
            # Use plain string checks to avoid ReDoS-prone compiled regex on user HTML
            if '/vis-job/' not in href and '/jobannonce/sign/' not in href:
                continue
            title = a.get_text(strip=True)
            url   = resolve_url(href)
            if title and url:
                jobs.append({'id': '', 'title': title, 'company': '', 'location': '', 'url': url, 'description': ''})

    return deduplicate(jobs)


def extract_from_tag_bs4(tag) -> Optional[dict]:
    from bs4 import BeautifulSoup
    # Title + URL — use plain string checks to avoid ReDoS on user-supplied HTML
    link = None
    for a_tag in tag.find_all('a', href=True):
        if '/vis-job/' in a_tag['href'] or '/jobannonce/sign/' in a_tag['href']:
            link = a_tag
            break
    if not link:
        h4 = tag.find('h4')
        link = h4.find('a') if h4 else None
    title = link.get_text(strip=True) if link else ''
    url   = resolve_url(link.get('href', '')) if link else ''

    if not title and not url:
        return None

    # Company
    company = ''
    for cls in ['company', 'employer', 'companyName', 'jix-toolbar__company']:
        el = tag.find(class_=re.compile(cls, re.I))
        if el:
            company = el.get_text(strip=True)
            break
    if not company:
        strong = tag.find('strong')
        if strong:
            company = strong.get_text(strip=True)

    # Location
    location = ''
    for cls in ['location', 'area', 'region', 'jix-toolbar__location']:
        el = tag.find(class_=re.compile(cls, re.I))
        if el:
            location = el.get_text(strip=True)
            break

    # Short description
    desc = ''
    for cls in ['description', 'snippet', 'teaser', 'jix-toolbar__body']:
        el = tag.find(class_=re.compile(cls, re.I))
        if el:
            desc = el.get_text(strip=True)[:500]
            break

    return {
        'id':          '',
        'title':       re.sub(r'\s+', ' ', title).strip(),
        'company':     re.sub(r'\s+', ' ', company).strip(),
        'location':    re.sub(r'\s+', ' ', location).strip(),
        'url':         url,
        'description': re.sub(r'\s+', ' ', desc).strip(),
    }


def parse_with_regex(html: str) -> list:
    """Fallback parser using plain string operations — no external deps needed.
    Deliberately avoids complex regex on user-supplied HTML to prevent ReDoS."""
    jobs = []
    seen: set = set()

    # Split on href=" — each chunk starts right after a href attribute value opening quote
    parts = html.split('href="')
    for chunk in parts[1:]:
        # Extract the href value up to the closing quote
        q = chunk.find('"')
        if q < 0:
            continue
        href = chunk[:q]
        if '/vis-job/' not in href and '/jobannonce/sign/' not in href:
            continue

        # Extract the anchor's text content: find '>' then text up to '</a>' or '<'
        gt = chunk.find('>', q)
        if gt < 0:
            continue
        after = chunk[gt + 1: gt + 201]  # limit look-ahead to 200 chars
        lt = after.find('<')
        title = (after[:lt] if lt >= 0 else after).strip()
        # Collapse whitespace
        title = ' '.join(title.split())
        if len(title) < 3:
            continue

        url = resolve_url(href)
        if url and url not in seen:
            seen.add(url)
            jobs.append({'id': '', 'title': title, 'company': '', 'location': '',
                         'url': url, 'description': ''})
    return jobs


def resolve_url(href: str) -> str:
    if not href:
        return ''
    if href.startswith('http'):
        return href
    if href.startswith('//'):
        return 'https:' + href
    return JOBINDEX_BASE + href


def deduplicate(jobs: list) -> list:
    seen, result = set(), []
    for j in jobs:
        key = j['url'] or (j['title'] + '|' + j['company'])
        if key and key not in seen:
            seen.add(key)
            result.append(j)
    return result


def scrape_jobindex(url: str) -> Tuple[List[dict], List[str]]:
    """
    Fetch all pages from jobindex.dk and return (jobs, errors).
    Scraping continues automatically until a page returns no results.
    All HTTP traffic goes directly from this machine to jobindex.dk.
    """
    all_jobs: list = []
    errors:   list = []
    page = 1

    while True:
        if page > 1:
            time.sleep(1)  # Polite delay between pages

        if page == 1:
            page_url = url
        else:
            sep      = '&' if '?' in url else '?'
            page_url = url + sep + 'page=' + str(page)

        try:
            html = fetch_page(page_url)
        except Exception as e:
            errors.append(f'Side {page}: {e}')
            break

        jobs = parse_with_bs4(html, page_url) if _USE_REQUESTS else parse_with_regex(html)
        if not jobs:
            break  # No more results — stop

        all_jobs.extend(jobs)
        page += 1

    return deduplicate(all_jobs), errors


# ── HTTP server ───────────────────────────────────────────────────────────

class Handler(BaseHTTPRequestHandler):

    def log_message(self, fmt, *args):
        print(f'[JobHunter]  {self.address_string()}  {fmt % args}')

    def _cors(self):
        # This server only runs on localhost. Use wildcard CORS so the hosted
        # website can connect without echoing back any user-supplied header values.
        self.send_header('Access-Control-Allow-Origin',  '*')
        self.send_header('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
        self.send_header('Access-Control-Allow-Headers', 'Content-Type')
        self.send_header('Access-Control-Max-Age',       '86400')

    def do_OPTIONS(self):
        self.send_response(204)
        self._cors()
        self.end_headers()

    def do_GET(self):
        path = self.path.split('?')[0]
        if path in ('/', '/health'):
            self._json({'ok': True, 'service': 'JobHunter Local Scraper', 'version': '1.0.0',
                        'bs4': _USE_REQUESTS})
        else:
            self._json({'error': 'Not found'}, 404)

    def do_POST(self):
        path = self.path.split('?')[0]
        if path != '/scrape':
            self._json({'error': 'Not found'}, 404)
            return

        length = int(self.headers.get('Content-Length', 0))
        raw    = self.rfile.read(length) if length else b'{}'
        try:
            body = json.loads(raw)
        except json.JSONDecodeError:
            self._json({'error': 'Invalid JSON'}, 400)
            return

        url       = body.get('url', '').strip()

        if not url:
            self._json({'error': 'url is required'}, 400)
            return
        if not re.match(r'^https?://(?:www\.)?jobindex\.dk/', url, re.I):
            self._json({'error': 'Only jobindex.dk URLs are allowed'}, 400)
            return

        print(f'[JobHunter]  Scraping: {url}  (alle sider)')
        try:
            jobs, errors = scrape_jobindex(url)
        except Exception as e:
            self._json({'error': str(e)}, 500)
            return

        print(f'[JobHunter]  Found {len(jobs)} jobs, {len(errors)} error(s)')
        self._json({'ok': True, 'jobs': jobs, 'errors': errors, 'total': len(jobs)})

    def _json(self, data: dict, code: int = 200):
        body = json.dumps(data, ensure_ascii=False).encode('utf-8')
        self.send_response(code)
        self.send_header('Content-Type',   'application/json; charset=utf-8')
        self.send_header('Content-Length', str(len(body)))
        self._cors()
        self.end_headers()
        self.wfile.write(body)


# ── Entry point ───────────────────────────────────────────────────────────

def main():
    port = DEFAULT_PORT
    if len(sys.argv) > 1:
        try:
            port = int(sys.argv[1])
        except ValueError:
            print(f'Ugyldig port: {sys.argv[1]}')
            sys.exit(1)

    server = HTTPServer(('localhost', port), Handler)

    if _USE_REQUESTS:
        print('[JobHunter]  BeautifulSoup tilgængelig – bruger fuld HTML-parsing')
    else:
        print('[JobHunter]  BeautifulSoup ikke installeret – falder tilbage på regex-parser')
        print('[JobHunter]  Kør:  pip install -r requirements.txt  for bedre resultater')

    print(f'[JobHunter]  Lokal scraper kører på  http://localhost:{port}')
    print( '[JobHunter]  Tryk Ctrl+C for at stoppe.')

    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print('\n[JobHunter]  Stoppet.')


if __name__ == '__main__':
    main()
