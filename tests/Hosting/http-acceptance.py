"""Real HTTP checks for the isolated development/production Docker profiles."""

import gzip
import hashlib
import json
import os
import pathlib
import re
import urllib.error
import urllib.parse
import urllib.request
import xml.etree.ElementTree as ET
from html.parser import HTMLParser

os.umask(0o077)
assert os.environ.get('GITHUB_ACTIONS') == 'true', 'Run only in the isolated Actions job.'
assert os.environ.get('ONESHOP_HTTP_ACCEPTANCE') == '1', 'Explicit HTTP acceptance acknowledgement required.'
target = urllib.parse.urlsplit(os.environ['ONESHOP_HTTP_ACCEPTANCE_URL'])
assert target.scheme in ('http', 'https') and target.hostname in ('127.0.0.1', 'localhost', 'nginx')
assert target.path in ('', '/') and not any((target.username, target.password, target.query, target.fragment))
base = urllib.parse.urlunsplit((target.scheme, target.netloc, '', '', ''))
profile = os.environ.get('ONESHOP_HTTP_PROFILE', 'production')
assert profile in ('production', 'development')
mode = os.environ.get('ONESHOP_EXPECT_INDEXING', 'disabled')
assert mode in ('disabled', 'enabled')
enabled = mode == 'enabled'
origin = os.environ.get('APP_URL', 'http://nginx').rstrip('/')
fixture_path = os.environ.get('ONESHOP_HTTP_FIXTURE')
fixture = json.loads(pathlib.Path(fixture_path).read_text()) if fixture_path else None
if enabled:
    assert fixture and re.fullmatch(r'/proizvodi/[1-9][0-9]{0,8}', fixture['product_path'])


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


opener = urllib.request.build_opener(NoRedirect())


def fetch(path, encoding='identity'):
    assert path.startswith('/') and not path.startswith('//')
    request = urllib.request.Request(base + path, headers={'Accept-Encoding': encoding})
    try:
        response = opener.open(request, timeout=30)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        return response.status, response.headers, response.read()


def nosniff(headers):
    assert headers.get_all('X-Content-Type-Options') == ['nosniff'], 'Exactly one nosniff header is required.'


def private(headers):
    cache = headers.get('Cache-Control', '')
    assert 'private' in cache and 'no-store' in cache
    assert headers.get('Content-Encoding') is None, 'Personalized HTML is not gzip compressed by this profile.'
    nosniff(headers)


class Head(HTMLParser):
    def __init__(self, body):
        super().__init__(convert_charrefs=True)
        self.assets = set()
        self.meta = {}
        self.canonical = None
        self.feed(body.decode('utf-8'))

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == 'meta':
            self.meta[attrs.get('property', attrs.get('name', ''))] = attrs.get('content')
        if tag == 'link' and attrs.get('rel') == 'canonical':
            self.canonical = attrs.get('href')
        asset = attrs.get('src') if tag == 'script' else attrs.get('href') if tag == 'link' else None
        if asset:
            path = urllib.parse.urlsplit(asset).path
            if re.fullmatch(r'/build/assets/[A-Za-z0-9_.-]+\.(?:css|js)', path):
                self.assets.add(path)


status, headers, body = fetch('/', 'gzip')
assert status == 200
private(headers)
head = Head(body)
robots = 'index,follow' if enabled else 'noindex,nofollow'
assert headers.get('X-Robots-Tag') == robots and head.meta.get('robots') == robots
assert any(path.endswith('.css') for path in head.assets) and any(path.endswith('.js') for path in head.assets)
assets = []
repo = pathlib.Path(__file__).resolve().parents[2]
for path in sorted(head.assets):
    status, identity_headers, identity = fetch(path)
    assert status == 200
    nosniff(identity_headers)
    cache = identity_headers.get('Cache-Control', '')
    assert all(value in cache for value in ('public', 'max-age=31536000', 'immutable'))
    mime = identity_headers.get_content_type()
    assert mime == 'text/css' if path.endswith('.css') else mime in ('application/javascript', 'text/javascript')
    status, compressed_headers, compressed = fetch(path, 'gzip')
    assert status == 200 and compressed_headers.get('Content-Encoding') == 'gzip'
    assert 'Accept-Encoding' in compressed_headers.get('Vary', '')
    assert gzip.decompress(compressed) == identity and len(compressed) < len(identity)
    nosniff(compressed_headers)
    local = repo / 'public' / path.lstrip('/')
    compared = local.is_file()
    if compared:
        assert not local.is_symlink() and local.resolve().is_relative_to((repo / 'public/build').resolve())
        assert local.read_bytes() == identity
    assets.append({'path': path, 'identity_bytes': len(identity), 'gzip_bytes': len(compressed),
                   'sha256': hashlib.sha256(identity).hexdigest(), 'local_build_compared': compared})
for path, expected in [('/build/assets/oneshop-missing.css', 404), ('/build/assets/.env', 403), ('/build/assets/script.PHP', 404)]:
    status, headers, _ = fetch(path, 'gzip')
    assert status == expected and 'immutable' not in headers.get('Cache-Control', '')
    nosniff(headers)

status, headers, body = fetch('/robots.txt')
assert status == 200 and headers.get_content_type() == 'text/plain'
assert headers.get_all('Set-Cookie') is None
assert headers.get('X-Robots-Tag') == 'noindex,nofollow'
nosniff(headers)
if enabled:
    assert ('Sitemap: ' + origin + '/sitemap.xml').encode() in body
else:
    assert b'Sitemap:' not in body


def sitemap(path, expected):
    status, headers, body = fetch(path)
    assert status == 200 and headers.get_content_type() == 'application/xml'
    assert headers.get_all('Set-Cookie') is None
    tree = ET.fromstring(body)
    assert [node.text for node in tree.iter() if node.tag.endswith('}loc')] == expected
    assert not any(node.tag.endswith('}lastmod') for node in tree.iter())


if enabled:
    assert head.canonical == origin + '/'
    sitemap('/sitemap.xml', [origin + '/sitemap-pages.xml', origin + '/sitemap-products-1.xml'])
    sitemap('/sitemap-pages.xml', [origin + '/'])
    sitemap('/sitemap-products-1.xml', [origin + fixture['product_path']])
    status, headers, body = fetch(fixture['product_path'])
    assert status == 200 and headers.get('X-Robots-Tag') == 'index,follow'
    assert Head(body).canonical == origin + fixture['product_path']
    status, headers, body = fetch('/?sort=newest')
    assert status == 200 and headers.get('X-Robots-Tag') == 'noindex,follow'
    assert Head(body).canonical is None
else:
    for path in ['/sitemap.xml', '/sitemap-pages.xml', '/sitemap-products-1.xml']:
        status, headers, _ = fetch(path)
        assert status == 404 and headers.get_all('Set-Cookie') is None
checkout_status = 302 if profile == 'development' else 404
for path, expected in [('/admin/login', 200), ('/kosarica', 200), ('/checkout', checkout_status), ('/admin/products', 302)]:
    status, headers, _ = fetch(path, 'gzip')
    assert status == expected and headers.get('X-Robots-Tag') == 'noindex,nofollow'
    private(headers)

report = {'indexing': 'enabled' if enabled else 'disabled', 'assets': assets, 'robots_dynamic_and_cookie_free': True,
          'sitemap_policy': 'passed', 'private_pages': 'noindex, private/no-store, no gzip',
          'protected_asset_paths': 'passed', 'orders_placed': 0,
          'scope': 'Isolated Docker real HTTP; synthetic domain only, no real TLS/merchant acceptance.'}
output = repo / 'storage/app/private/docker-http-qa'
output.mkdir(parents=True, exist_ok=True, mode=0o700)
stage = os.environ.get('ONESHOP_HTTP_STAGE', 'default')
assert stage in ('default', 'before-launch', 'public-launch', 'after-launch')
report['stage'] = stage
(output / (profile + '-' + report['indexing'] + '-' + stage + '.json')).write_text(json.dumps(report, indent=2) + '\n')
print(json.dumps(report))
