"""Computed sizing audit including conditional selects and bounded forum fixtures."""
import argparse
import json
import mimetypes
from pathlib import Path
from urllib.parse import urlparse
from playwright.sync_api import sync_playwright, expect

parser=argparse.ArgumentParser()
parser.add_argument('fixtures',type=Path)
parser.add_argument('report',type=Path)
parser.add_argument('--match')
parser.add_argument('--chrome',default='/Applications/Google Chrome.app/Contents/MacOS/Google Chrome')
args=parser.parse_args()
root=Path(__file__).resolve().parents[2]
fixtures=json.loads(args.fixtures.read_text())
selected=[]
for match in ['dropdown-sizing-fixture','section=api','section=profile','/invitations/create','prefix_search_renders','size=s','size=l','size=xl']:
    fixture=next((f for f in fixtures if match in f['uri'] or match in f['test']),None)
    if fixture and fixture not in selected:selected.append(fixture)
for size in ['s','l','xl']:
    fixture=next(f for f in fixtures if f['uri']=='/browse/tv?view=covers&size='+size)
    selected.append(fixture)
if args.match: selected=[f for f in selected if args.match in f['uri']]
records=[]
with sync_playwright() as pw:
    browser=pw.chromium.launch(executable_path=args.chrome,headless=True)
    current={}
    def respond(route):
        path=urlparse(route.request.url).path
        if path=='/fixture':route.fulfill(body=current['html'],content_type='text/html');return
        asset=root/'public'/path.lstrip('/')
        if asset.is_file() and asset.resolve().is_relative_to((root/'public').resolve()):route.fulfill(body=asset.read_bytes(),content_type=mimetypes.guess_type(asset)[0] or 'application/octet-stream')
        else:route.abort()
    context=browser.new_context()
    context.route('**/*',respond)
    page=context.new_page()
    def measure(stage,width,scheme,dark):
        result=page.evaluate('''()=>[...document.querySelectorAll('select')].filter(e=>e.checkVisibility({checkVisibilityCSS:true})).map(e=>{let b=e.getBoundingClientRect(),p=e.parentElement.getBoundingClientRect(),s=getComputedStyle(e);return {label:e.ariaLabel||e.id||e.name,width:b.width,left:b.left,right:b.right,container:e.parentElement.className,containerWidth:p.width,rule:e.className,font:s.fontSize}})''')
        for control in result:
            assert control['width']<=control['containerWidth']+1,control
            assert control['left']>=-1 and control['right']<=width+1,control
        assert page.evaluate('document.documentElement.scrollWidth')<=width+1
        if current['uri'].startswith('/browse/tv'):
            if width==1869:
                controls=page.locator('.release-browser-toolbar').first.locator('input[name=q],select[name=year],select[name=network],select[name=sort]')
                assert controls.count()==4
                tops=controls.evaluate_all('(els)=>els.map(e=>e.getBoundingClientRect().top)')
                assert max(tops)-min(tops)<1,tops
            for row in page.locator('.release-cover-metadata').all():
                badge=row.locator('.release-chip').first.bounding_box()
                watch=row.locator('.watchlist-button').first.bounding_box()
                if badge and watch and abs(badge['y']-watch['y'])<1:
                    assert abs(badge['height']-watch['height'])<1 and badge['height']>=36,(badge,watch)
        records.append({'fixture':current['uri'],'stage':stage,'viewport':width,'scheme':scheme,'dark':dark,'controls':result})
    for current in selected:
        for width,height in [(390,844),(768,1024),(1869,1280),(2560,1440)]:
            page.set_viewport_size({'width':width,'height':height})
            page.goto('http://localhost/fixture',wait_until='networkidle')
            for scheme in ['blue','emerald','violet']:
                for dark in [False,True]:
                    page.evaluate('([s,d])=>{document.documentElement.dataset.colorScheme=s;document.documentElement.classList.toggle("dark",d)}',[scheme,dark])
                    measure('default',width,scheme,dark)
                    if page.locator('#feed-type').count():
                        page.locator('#feed-type').select_option('category')
                        expect(page.locator('#feed-category')).to_be_visible()
                        measure('RSS category',width,scheme,dark)
                    if page.locator('.search-filter-menu').count():
                        page.locator('.search-filter-menu').evaluate('(e)=>e.open=true')
                        for kind in ['cat','minc']:
                            page.locator('#search-filter-kind').select_option(kind)
                            expect(page.locator('#search-filter-completion' if kind=='minc' else '#search-filter-category')).to_be_visible()
                            measure('search filter '+kind,width,scheme,dark)
                        page.locator('.search-filter-menu').evaluate('(e)=>e.open=false')
                    if page.locator('[data-row-action="report"]').count():
                        page.locator('[data-row-action="report"]').first.click()
                        expect(page.locator('#shared-report-reason')).to_be_visible()
                        measure('report dialog',width,scheme,dark)
                        page.keyboard.press('Escape')
        args.report.write_text(json.dumps(records,indent=2))
        print('Dropdown audit passed:',current['uri'],flush=True)
    context.close()
    browser.close()
args.report.write_text(json.dumps(records,indent=2))
print(len(records),'conditional sizing measurements')
