"""Exercise year filtering against synthetic controller HTML and the built application JS.

Capture inputs with capture_frontend.py --only ReleaseBrowserControllerTest TitleControllerTest
--filter='test_every_year_capable_category_uses_its_own_year_in_every_supported_view|test_directory_year_and_title_air_year_remain_distinct_through_ranges_and_legacy_links'.
"""
import argparse
import json
import mimetypes
from pathlib import Path
from urllib.parse import urlparse, parse_qs
from playwright.sync_api import sync_playwright, expect

parser = argparse.ArgumentParser()
parser.add_argument('fixtures', type=Path)
parser.add_argument('report', type=Path)
parser.add_argument('--match', help='Limit contexts by path substring')
parser.add_argument('--chrome', default='/Applications/Google Chrome.app/Contents/MacOS/Google Chrome')
args = parser.parse_args()
root = Path(__file__).resolve().parents[2]
fixtures = json.loads(args.fixtures.read_text())


def key(url):
    parsed = urlparse(url)
    query = parse_qs(parsed.query, keep_blank_values=True)
    year = query.get('year', [''])[0]
    if year == 'broken': year = ''
    if year == 'custom' and not query.get('year_from', [''])[0] and not query.get('year_to', [''])[0]: year = ''
    return (parsed.path, query.get('view', ['table'])[0], year,
            query.get('year_from', [''])[0] if year == 'custom' else '',
            query.get('year_to', [''])[0] if year == 'custom' else '')

pages = {}
fragments = {}
for fixture in fixtures:
    if '_fragment=releases' in fixture['uri']:
        fragments.setdefault(key(fixture['uri']), fixture['html'])
    if any(part in fixture['uri'] for part in ['_fragment=', 'group=', 'poster=', 'watching=', '%5B', 'year[']): continue
    pages.setdefault(key(fixture['uri']), fixture['html'])
records = []
with sync_playwright() as pw:
    browser = pw.chromium.launch(executable_path=args.chrome, headless=True)
    context = browser.new_context()
    errors = []
    missing = []
    def respond(route):
        path = urlparse(route.request.url).path
        asset = root / 'public' / path.lstrip('/')
        if asset.is_file() and asset.resolve().is_relative_to((root / 'public').resolve()):
            route.fulfill(body=asset.read_bytes(), content_type=mimetypes.guess_type(asset)[0] or 'application/octet-stream')
        elif '_fragment=releases' in route.request.url:
            html = fragments.get(key(route.request.url))
            if html is None:
                missing.append(route.request.url)
                route.fulfill(status=404, body='Missing synthetic fragment')
            else: route.fulfill(body=html, content_type='text/html')
        elif route.request.resource_type == 'document':
            html = pages.get(key(route.request.url))
            if html is None:
                missing.append(route.request.url)
                route.fulfill(status=404, body='Missing synthetic response')
            else: route.fulfill(body=html, content_type='text/html')
        else: route.abort()
    context.route('**/*', respond)
    page = context.new_page()
    page.on('pageerror', lambda error: errors.append(str(error)))
    contexts = [(f'/browse/{category}', view) for category in ['movies', 'audio', 'console', 'games', 'books', 'xxx']
                for view in (['table', 'covers'] if category == 'games' else ['table', 'cards', 'covers'])]
    if args.match: contexts = [item for item in contexts if args.match in item[0]]
    for path, view in contexts:
        page.set_viewport_size({'width': 390, 'height': 844})
        def load():
            page.goto('http://localhost' + path + '?view=' + view + '&year=1970s', wait_until='networkidle')
            page.wait_for_function('window.Alpine !== undefined')
        def count(expected):
            selector = '[data-cover-tile]' if view == 'covers' else '[data-release-row]'
            expect(page.locator(selector)).to_have_count(expected)
        def picker(): return page.locator('[data-year-picker]').first
        def submit():
            picker().locator('[data-year-apply]').click()
            page.wait_for_load_state('networkidle')
        load()
        count(3)
        expect(picker().locator('option[value="1900"]')).to_have_count(1)
        expect(picker().locator('option[value="1900s"]')).to_have_count(1)
        picker().locator('select').select_option('1975')
        page.wait_for_load_state('networkidle')
        count(1)
        for start, end, expected in [('1970', '1975', 2), ('1975', '', 3), ('', '1975', 3), ('1980', '1970', 4), ('', '', 5)]:
            before = page.url
            picker().locator('select').select_option('custom')
            assert page.url == before, 'Selecting Custom Range navigated before bounds could be entered'
            expect(picker().locator('[name=year_from]')).to_be_visible()
            picker().locator('[name=year_from]').fill(start)
            picker().locator('[name=year_to]').fill(end)
            submit()
            count(expected)
            params = parse_qs(urlparse(page.url).query)
            assert params.get('year_from', [''])[0] == start and params.get('year_to', [''])[0] == end, page.url
        # Applying with Enter uses the same complete range.
        picker().locator('select').select_option('custom')
        picker().locator('[name=year_from]').fill('1970')
        picker().locator('[name=year_to]').fill('1975')
        picker().locator('[name=year_to]').press('Enter')
        page.wait_for_load_state('networkidle')
        count(2)
        for width in [390, 639, 640, 641, 699, 700, 701, 768, 1024, 1099, 1100, 1101, 1869, 2560]:
            page.set_viewport_size({'width': width, 'height': 1000})
            for scheme in ['coral']:
                for dark in [False, True]:
                    page.evaluate('([s,d])=>{document.documentElement.classList.toggle("dark",d)}', [scheme, dark])
                    for control in picker().locator('select,input,button').all():
                        box = control.bounding_box()
                        assert box and box['x'] >= 0 and box['x'] + box['width'] <= width + 1, (path, view, width, box)
                    assert page.evaluate('document.documentElement.scrollWidth') <= width + 1, (path, view, width)
                    records.append({'path': path, 'view': view, 'width': width, 'scheme': scheme, 'dark': dark})
        picker().locator('[data-year-clear]').click()
        page.wait_for_load_state('networkidle')
        count(5)
        assert not any(name in parse_qs(urlparse(page.url).query) for name in ['year', 'year_from', 'year_to'])
        picker().locator('select').select_option('custom')
        picker().locator('[name=year_from]').fill('1970')
        picker().locator('[name=year_to]').fill('1975')
        submit()
        picker().locator('select').select_option('')
        page.wait_for_load_state('networkidle')
        count(5)
        assert not any(name in parse_qs(urlparse(page.url).query) for name in ['year', 'year_from', 'year_to'])
        assert not missing, missing
        assert not errors, errors
        args.report.write_text(json.dumps(records, indent=2))
        print('Year interactions and layout passed:', path, view, flush=True)
    context.close()
    browser.close()
print(len(records), 'layout measurements; all year interactions passed')
