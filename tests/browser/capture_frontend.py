"""Capture synthetic HTTP fixtures from the feature tests for layout regression checks."""
import argparse
import base64
import json
from pathlib import Path
import re
import subprocess
import tempfile

parser = argparse.ArgumentParser()
parser.add_argument('output', type=Path)
parser.add_argument('--only', nargs='+')
args = parser.parse_args()
root = Path(__file__).resolve().parents[2]
cases = {
    'PosterIdentityControllerTest': 'page_matches_exact_identity',
    'DetailsControllerTest': 'details_header_uses',
    'PublicDropdownSizingTest': 'forum_selection',
    'SearchControllerTest': 'prefix_search_renders',
    'ReleaseBrowserControllerTest': 'long_cover_metadata|tv_covers_group_identified|large_covers_render|other_root_filters|table_renders|toolbar_and_both|poster_identity_filter|movie_filters|search_accepts',
    'TitleControllerTest': 'show_dialog_bounds|tv_directory|complete_long_cast|titles_without',
    'PublicShellTest': 'only_administrators|shared_search',
    'AccountControllerTest': 'sections_render|invitation_sections|public_api_help|themed_errors',
}
fixtures = []
for original, selection in cases.items():
    if args.only and original not in args.only: continue
    source = (root / 'tests/Feature' / (original + '.php')).read_text()
    with tempfile.NamedTemporaryFile(prefix='CapturedFrontend', suffix='Test.php', dir=root / 'tests/Feature', delete=False) as handle:
        path = Path(handle.name)
    try:
        source = source.replace('class ' + original, 'class ' + path.stem).replace('$this->withoutVite();', '')
        source = source.rstrip()[:-1] + '''
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $response = parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
        if ($method === 'GET' && $response->status() === 200) {
            fwrite(STDOUT, "\\nFRONTEND_FIXTURE:".base64_encode(json_encode([
                'test' => $this->name(), 'uri' => (string) $uri,
                'html' => $response->getContent(),
            ], JSON_THROW_ON_ERROR))."\\n");
        }
        return $response;
    }
}
'''
        path.write_text(source)
        result = subprocess.run(['scripts/agent-sail', 'artisan', 'test', '--compact', str(path.relative_to(root)), '--filter=' + selection], cwd=root, capture_output=True, text=True)
        for encoded in re.findall(r'FRONTEND_FIXTURE:([A-Za-z0-9+/=]+)', result.stdout):
            fixtures.append(json.loads(base64.b64decode(encoded)))
        clean = re.sub(r'FRONTEND_FIXTURE:[A-Za-z0-9+/=]+', '', result.stdout)
        print(original, clean[-1800:], flush=True)
        if result.returncode:
            raise RuntimeError(result.stderr + clean[-4000:])
    finally:
        path.unlink(missing_ok=True)
args.output.write_text(json.dumps(fixtures))
print(f'Captured {len(fixtures)} synthetic responses to {args.output}')
