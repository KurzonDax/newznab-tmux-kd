"""Measure built public pages captured by capture_frontend.py; requires Playwright."""
import argparse
import json
import mimetypes
from pathlib import Path
from urllib.parse import urlparse
from playwright.sync_api import sync_playwright

parser = argparse.ArgumentParser()
parser.add_argument('fixtures', type=Path)
parser.add_argument('report', type=Path)
parser.add_argument('--chrome', default='/Applications/Google Chrome.app/Contents/MacOS/Google Chrome')
args = parser.parse_args()
root = Path(__file__).resolve().parents[2]
fixtures = json.loads(args.fixtures.read_text())
viewports = [(390,844), (768,1024), (1024,768), (1869,1280), (2560,1440), (639,900), (640,900), (641,900), (699,900), (700,900), (701,900), (1099,900), (1100,900), (1101,900)]
reports = []
failures = []
with sync_playwright() as pw:
    browser = pw.chromium.launch(executable_path=args.chrome, headless=True)
    context = browser.new_context()
    current = {}
    def route_handler(route):
        path = urlparse(route.request.url).path
        if path == '/fixture':
            route.fulfill(body=current['html'], content_type='text/html')
            return
        asset = root / 'public' / path.lstrip('/')
        if asset.is_file() and asset.resolve().is_relative_to((root/'public').resolve()):
            route.fulfill(body=asset.read_bytes(), content_type=mimetypes.guess_type(asset)[0] or 'application/octet-stream')
        elif path.startswith('/api/'):
            route.fulfill(body='[]', content_type='application/json')
        else:
            route.abort()
    context.route('**/*', route_handler)
    page = context.new_page()
    # Keep distinct page/data fixtures; repeated sort requests share layout.
    seen = set()
    for fixture in fixtures:
        if '<!DOCTYPE html>' not in fixture['html']: continue
        identity = fixture['test'] + ':' + fixture['uri'].split('&sort=')[0]
        if identity in seen: continue
        seen.add(identity)
        current = fixture
        for width,height in viewports:
            page.set_viewport_size({'width':width,'height':height})
            page.goto('http://nntmux.test/fixture', wait_until='networkidle')
            page.evaluate('document.fonts.ready')
            for scheme in ['coral']:
                for dark in [False,True]:
                    page.evaluate('([scheme,dark])=>{document.documentElement.classList.toggle("dark",dark)}', [scheme,dark])
                    measurements = page.evaluate('''() => {
                        const box=e=>{const r=e.getBoundingClientRect();return {left:r.left,right:r.right,top:r.top,bottom:r.bottom,width:r.width,height:r.height}};
                        const visible=e=>e.checkVisibility({checkVisibilityCSS:true});
                        const main=document.querySelector('main.public-page');
                        const footer=document.querySelector('.public-footer');
                        const errors=[];
                        document.querySelectorAll('.release-cover-tile').forEach(card=>{
                            const b=box(card);
                            card.querySelectorAll('*').forEach(child=>{
                                if(!visible(child))return;
                                const c=box(child);
                                if(c.left<b.left-1||c.right>b.right+1)errors.push('card overflow: '+child.className);
                            });
                        });
                        document.querySelectorAll('[data-release-facts-row]').forEach(facts=>{
                            const name=facts.parentElement.querySelector('[data-release-title]');
                            if(name && visible(name) && box(facts).top < box(name).bottom-1)errors.push('facts share title line');
                        });
                        const controls=[...document.querySelectorAll('select')].filter(visible).map(e=>({label:e.ariaLabel||e.id||e.name,container:e.parentElement.className,rule:getComputedStyle(e).width,...box(e),containerWidth:box(e.parentElement).width,font:getComputedStyle(e).fontSize}));
                        controls.forEach(c=>{if(c.width>c.containerWidth+1)errors.push('select exceeds container: '+c.label)});
                        return {main:main?box(main):null,footer:footer?box(footer):null,scrollWidth:document.documentElement.scrollWidth,scrollHeight:document.documentElement.scrollHeight,controls,errors,fontLoaded:document.fonts.check('14px Manrope')};
                    }''')
                    errors=measurements['errors']
                    if measurements['main']:
                        expected=width-24 if width<=700 else width*.8
                        if abs(measurements['main']['width']-expected)>1: errors.append('page width')
                        if abs(measurements['main']['left']-(width-expected)/2)>1: errors.append('page centering')
                    if measurements['scrollWidth']>width+1: errors.append('horizontal page overflow')
                    if measurements['footer'] and measurements['footer']['bottom']<height-1: errors.append('footer above viewport bottom')
                    if not measurements['fontLoaded']: errors.append('Manrope missing')
                    record={'test':fixture['test'],'uri':fixture['uri'],'viewport':[width,height],'scheme':scheme,'dark':dark,**measurements}
                    reports.append(record)
                    if errors: failures.append({k:record[k] for k in ['test','uri','viewport','scheme','dark','errors']})
        args.report.write_text(json.dumps({'measurements':reports,'failures':failures},indent=2))
        print(f"Measured {fixture['test']} {fixture['uri']}; {len(failures)} failures",flush=True)
    context.close()
    browser.close()
args.report.write_text(json.dumps({'measurements':reports,'failures':failures},indent=2))
print(f'{len(reports)} measurements; {len(failures)} failures')
if failures: raise SystemExit(1)
