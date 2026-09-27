#!/usr/bin/env python3
"""Language data for the Language / Audio filters (2026-09-26): mv/langs.json (Movies) and langs.json (TV).
   python3 gen-languages.py                 # the private datasets
   python3 gen-languages.py --tv-only <dir> # the invented TV dataset of a public copy

Audio: the languages of a release's audio tracks, from the media info already in the prototypes (media.json: the newest
probe's tracks, else the legacy audio_data rows, as gen-movies-data.py and the TV generator copied them from the lab).
Original language: films from the TMDB sample on disk (data/movies/raw, never topped up); TV shows from meta.json.

One structural rule, no heuristics: the base code or name before any region ("en-US", "pt-BR", "English (US)",
"French_Canadian") names the language; codes are looked up in the table below; a value not in it is kept as written.
Codes that are not a language by the standard (zxx no speech, mul multiple, und undetermined, qaa-qtz local use) are dropped.
"""
import json, glob, os, re

NAMES = {'ar': 'Arabic', 'bg': 'Bulgarian', 'bn': 'Bengali', 'br': 'Breton', 'ca': 'Catalan', 'cmn': 'Chinese', 'mandarin': 'Chinese', 'cn': 'Cantonese',
  'cs': 'Czech', 'da': 'Danish', 'de': 'German', 'el': 'Greek', 'en': 'English', 'es': 'Spanish', 'fi': 'Finnish', 'fil': 'Filipino',
  'fr': 'French', 'he': 'Hebrew', 'hi': 'Hindi', 'hr': 'Croatian', 'hu': 'Hungarian', 'id': 'Indonesian', 'it': 'Italian', 'ja': 'Japanese',
  'kn': 'Kannada', 'ko': 'Korean', 'lv': 'Latvian', 'ml': 'Malayalam', 'ms': 'Malay', 'nb': 'Norwegian', 'nl': 'Dutch',
  'no': 'Norwegian', 'pl': 'Polish', 'pt': 'Portuguese', 'ro': 'Romanian', 'ru': 'Russian', 'sk': 'Slovak', 'sl': 'Slovenian', 'sr': 'Serbian',
  'sv': 'Swedish', 'ta': 'Tamil', 'te': 'Telugu', 'th': 'Thai', 'tr': 'Turkish', 'uk': 'Ukrainian', 'vi': 'Vietnamese', 'yue': 'Cantonese',
  'zh': 'Chinese', 'ko': 'Korean', 'eng': 'English', 'fre': 'French', 'fra': 'French', 'ger': 'German', 'deu': 'German', 'spa': 'Spanish',
  'ita': 'Italian', 'jpn': 'Japanese', 'kor': 'Korean', 'hin': 'Hindi', 'por': 'Portuguese', 'rus': 'Russian', 'chi': 'Chinese', 'zho': 'Chinese'}
NOT_A_LANGUAGE = re.compile(r'^(zxx|mul|und|q[a-t][a-z])$', re.I)

def name(v):
    v = (v or '').strip()
    if not v:
        return None
    base = re.split(r'[-_]| \(', v, 1)[0].strip()
    if NOT_A_LANGUAGE.match(base):
        return None
    return NAMES.get(base.lower(), base)

def audio(media_path):
    out = {}
    for rid, m in json.load(open(media_path)).items():
        seen = []
        for t in m.get('a', []):
            n = name(t.get('lang'))
            if n and n not in seen:
                seen.append(n)
        if seen:
            out[rid] = seen
    return out

import sys
if len(sys.argv) > 2 and sys.argv[1] == '--tv-only':
    # the public copy: audio languages for the invented TV dataset in <dir> (build-public-copy.sh)
    d = sys.argv[2]
    json.dump({'audio': audio(f'{d}/media.json')}, open(f'{d}/langs.json', 'w'), ensure_ascii=False, separators=(',', ':'))
    sys.exit(0)
films = {}
ids = set(open('mv/films.txt').read().split())
for f in glob.glob('data/movies/raw/*.json'):
    i = os.path.basename(f)[:-5]
    if i in ids:
        d = json.load(open(f))
        if not d.get('_404') and name(d.get('original_language')):
            films[i] = name(d.get('original_language'))
mv = {'audio': audio('mv/media.json'), 'film': films}
json.dump(mv, open('mv/langs.json', 'w'), ensure_ascii=False, separators=(',', ':'))
tv = {'audio': audio('media.json')}
json.dump(tv, open('langs.json', 'w'), ensure_ascii=False, separators=(',', ':'))
nrel = len(json.load(open('mv/data.json'))['rel'])
print('movies: releases with audio language', len(mv['audio']), 'of', nrel, '; films with original language', len(films), 'of', len(ids))
print('tv: releases with audio language', len(tv['audio']), 'of', len(json.load(open('data.json'))['rel']) if 'rel' in json.load(open('data.json')) else '?')
