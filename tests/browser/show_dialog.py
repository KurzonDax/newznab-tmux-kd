"""Exercise real rendered show fragments with built Alpine assets and synthetic data."""
import argparse
import json
import mimetypes
from pathlib import Path
from urllib.parse import urlparse, parse_qs
from playwright.sync_api import sync_playwright, expect

parser = argparse.ArgumentParser()
parser.add_argument('fixtures', type=Path)
parser.add_argument('--chrome', default='/Applications/Google Chrome.app/Contents/MacOS/Google Chrome')
args = parser.parse_args()
root = Path(__file__).resolve().parents[2]
fixtures = [f for f in json.loads(args.fixtures.read_text()) if f['test'].startswith('test_show_dialog_bounds')]
directory = next(f['html'] for f in fixtures if f['uri'] == '/series')
errors = []
with sync_playwright() as pw:
    browser = pw.chromium.launch(executable_path=args.chrome, headless=True)
    def respond(route):
        url = urlparse(route.request.url)
        query = parse_qs(url.query)
        if url.path == '/series':
            if not query:
                route.fulfill(body=directory, content_type='text/html'); return
            keys = ['_fragment','show'] if query.get('_fragment') == ['show'] else ['_fragment','show','season','kind','page','per']
            if query.get('kind') == ['episode']: keys.append('episode')
            fixture = next((f for f in fixtures if all(parse_qs(urlparse(f['uri']).query).get(k)==query.get(k) for k in keys)), None)
            if fixture:
                route.fulfill(body=fixture['html'],content_type='text/html'); return
            errors.append('Missing response: '+route.request.url)
        asset = root/'public'/url.path.lstrip('/')
        if asset.is_file() and asset.resolve().is_relative_to((root/'public').resolve()):
            route.fulfill(body=asset.read_bytes(), content_type=mimetypes.guess_type(asset)[0] or 'application/octet-stream'); return
        if '/watchlist/picker/' in url.path or '/watchlist/' in url.path:
            # Picker transport is tested in WatchlistControllerTest; keep UI context deterministic here.
            route.fulfill(body=json.dumps({'id':1,'root':'tv','title':'Harbor Street','listName':'My Shows','watched':False,'categories':[{'id':5030,'label':'HD'},{'id':5040,'label':'UHD'}],'selected':[5030,5040]}), content_type='application/json'); return
        route.abort()
    for width,height in [(1869,1280),(768,1024),(390,844)]:
        context=browser.new_context(viewport={'width':width,'height':height})
        context.route('**/*',respond)
        page=context.new_page()
        page.on('console',lambda message: print('CONSOLE:',message.type,message.text,flush=True))
        page.on('requestfailed',lambda request: print('FAILED:',request.url,request.failure,flush=True))
        page.on('pageerror',lambda error: (errors.append(str(error)), print('JS:', error,flush=True)))
        page.goto('http://localhost/series',wait_until='networkidle')
        opener=page.locator('.tv-directory-title').first
        opener.click()
        dialog=page.locator('.public-modal-show')
        expect(dialog).to_be_visible()
        expect(dialog.locator('[data-season-host] > section')).to_have_attribute('data-season','4')
        expect(dialog.locator('[data-show-season]')).to_have_text(['Season 3','Season 4','Specials'])
        episode=dialog.locator('[data-episode-section="1"]')
        episode.locator('summary').click()
        episode.locator('[data-expand-episode]').click()
        variants=episode.locator('[data-kind="episode"]')
        expect(variants.locator('.release-cover-release')).to_have_count(24)
        variants.locator('[data-list-page="2"]').click()
        expect(episode.locator('[data-kind="episode"]')).to_have_attribute('data-page','2')
        packs=dialog.locator('[data-pack-section]')
        packs.locator('summary').click()
        packs.locator('[data-expand-packs]').click()
        expect(packs.locator('.release-cover-release')).to_have_count(24)
        packs.locator('[data-list-page="2"]').click()
        expect(packs.locator('.release-cover-release')).to_have_count(16)
        dialog.locator('[data-show-season="3"]').click()
        expect(dialog.locator('[data-season-host] > section')).to_have_attribute('data-season','3')
        dialog.locator('[data-show-season="4"]').click()
        expect(dialog.locator('[data-episode-section="1"] [data-kind="episode"]')).to_have_attribute('data-page','2')
        expect(dialog.locator('[data-kind="packs"]')).to_have_attribute('data-page','2')
        season=dialog.locator('[data-season-host] > section')
        season.locator(':scope > nav [data-list-page="2"]').click()
        expect(dialog.locator('[data-season-host] > section')).to_have_attribute('data-page','2')
        expect(dialog.locator('[data-episode-section]')).to_have_count(6)
        dialog.locator('[data-season-host] > section > nav [data-list-page="1"]').click()
        expect(dialog.locator('[data-episode-section="1"] [data-kind="episode"]')).to_have_attribute('data-page','2')
        expect(dialog.locator('[data-kind="packs"]')).to_have_attribute('data-page','2')
        for scheme in ['coral']:
            for dark in [False,True]:
                page.evaluate('([scheme,dark])=>{document.documentElement.classList.toggle("dark",dark)}',[scheme,dark])
                bounds=dialog.locator('.public-modal-card').bounding_box()
                assert bounds['width']<=min(1050,width-40)+1 and bounds['height']<=height*.85+1, bounds
                assert page.evaluate('document.documentElement.scrollWidth')<=width
        watch=dialog.locator('[data-watch-picker]').first
        watch.click()
        picker=page.locator('.public-modal-picker')
        expect(picker).to_be_visible()
        expect(dialog).to_be_visible()
        page.keyboard.press('Escape')
        expect(picker).to_be_hidden()
        expect(dialog).to_be_visible()
        expect(watch).to_be_focused()
        page.keyboard.press('Escape')
        expect(dialog).to_be_hidden()
        expect(opener).to_be_focused()
        page.locator('.tv-directory-poster > button').first.click()
        expect(dialog).to_be_visible()
        dialog.click(position={'x':2,'y':2})
        expect(dialog).to_be_hidden()
        print(f'Show dialog navigation, independent pages, state, focus and containment passed at {width}px',flush=True)
        context.close()
    browser.close()
assert not errors, errors
