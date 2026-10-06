"""Download icons declared by the 23 official sites; keep source URLs locally."""
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime, timezone
from hashlib import sha256
from html.parser import HTMLParser
import json
from pathlib import Path
import subprocess
from urllib.parse import urljoin, urlsplit
from urllib.request import Request, urlopen

ROOT = Path(__file__).resolve().parents[1]
OUTPUT = ROOT / 'public/assets/company-icons'
OVERRIDES = {'about.mercari.com': 'https://careers.mercari.com/', 'www.sony.com': 'https://www.sony.co.jp/'}

class Icons(HTMLParser):
    def __init__(self):
        super().__init__()
        self.icons = []
    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == 'link' and any(rel in attrs.get('rel', '').lower().split() for rel in ['icon', 'apple-touch-icon', 'apple-touch-icon-precomposed']):
            if attrs.get('href'):
                self.icons.append(attrs['href'])

def fetch(url):
    if urlsplit(url).scheme != 'https':
        raise ValueError('Only HTTPS icon sources are allowed')
    with urlopen(Request(url, headers={'User-Agent': 'Mozilla/5.0 (compatible; ShioriLocalApp/1.0)'}), timeout=15) as response:
        return response.read(3_000_000), response.url

def extension(data):
    if data.startswith(b'\x89PNG\r\n\x1a\n'): return 'png'
    if data.startswith(b'\x00\x00\x01\x00'): return 'ico'
    if data.startswith(b'GIF8'): return 'gif'
    if data.startswith(b'\xff\xd8\xff'): return 'jpg'
    # SVG is safe only after separately reviewing/sanitizing; prefer raster here.
    return None

def download(row):
    name, _, _, _, site, _ = row
    host = urlsplit(site).hostname
    errors, candidates = [], []
    for page in dict.fromkeys([site, OVERRIDES.get(host, site)]):
        try:
            html, final = fetch(page)
            parser = Icons(); parser.feed(html.decode('utf-8', errors='replace'))
            candidates.extend((urljoin(final, link), final) for link in parser.icons)
        except Exception as error:
            errors.append(str(error))
        candidates.append((urljoin(page, '/favicon.ico'), page))
    seen = set()
    for url, page in candidates:
        if url in seen: continue
        seen.add(url)
        try:
            data, final = fetch(url)
            ext = extension(data)
            if not ext: continue
            filename = sha256(host.encode()).hexdigest()[:16] + '.' + ext
            (OUTPUT / filename).write_bytes(data)
            return host, {'name': name, 'path': '/assets/company-icons/' + filename, 'source_url': final, 'page_url': page, 'retrieved_at': datetime.now(timezone.utc).isoformat()}
        except Exception as error:
            errors.append(str(error))
    return host, {'name': name, 'path': '', 'page_url': site, 'error': '; '.join(errors[-2:]) or 'No raster favicon declared'}

if __name__ == '__main__':
    OUTPUT.mkdir(parents=True, exist_ok=True)
    rows = json.loads(subprocess.check_output(['php', '-r', "echo json_encode(require 'database/companies.php');"], cwd=ROOT))
    manifest_path = OUTPUT / 'manifest.json'
    manifest = json.loads(manifest_path.read_text()) if manifest_path.exists() else {}
    rows = [row for row in rows if not manifest.get(urlsplit(row[4]).hostname, {}).get('path')]
    with ThreadPoolExecutor(max_workers=6) as pool:
        manifest.update(dict(pool.map(download, rows)))
    (OUTPUT / 'manifest.json').write_text(json.dumps(manifest, ensure_ascii=False, indent=2))
    for host, record in manifest.items(): print(('OK' if record['path'] else 'MISSING'), record['name'], host, record.get('error', ''))
