/**
 * The media info block (docs/proposals/tv-redesign/SPEC.md 3.5): one renderer for the details
 * page's Media info tab and the media info dialog. It reads the plain names the server's
 * MediaInfoNames presenter adds to each stream (codec_name, format_name, channels_name, hdr,
 * picture, language_name) and never prints a codec id. The release's own resolution drives the
 * resolution chip. The stored overall bit rate is left out until it is understood (SPEC 7.5).
 */

const RESOLUTION_TONES = { '4K': '4k', '1080p': '1080', '720p': '720', SD: 'sd' };
const CHANNEL_TONES = { '7.1': '7-1', '5.1': '5-1', Stereo: '2-0' };
const HDR_TONES = { dv: 'dv', hdr10plus: 'hdr10plus', hdr: 'hdr' };
const SECTION_TONES = { Video: 'video', Audio: 'audio', Subtitles: 'subtitles' };
/** A subtitle list with no per-track detail collapses to a language grid past this many tracks. */
export const BARE_SUBTITLE_LIMIT = 8;

export function escapeHtml(value) {
    const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
    return String(value).replace(/[&<>"']/g, character => map[character]);
}

function hasValue(value) {
    return value !== null && value !== undefined && value !== '';
}

function unique(values) {
    return [...new Set(values.filter(hasValue))];
}

/** "English, Korean and 3 more". */
function few(values, limit = 3) {
    return values.length <= limit ? values.join(', ') : values.slice(0, limit).join(', ') + ' and ' + (values.length - limit) + ' more';
}

function plural(count, noun) {
    return count + ' ' + noun + (count === 1 ? '' : 's');
}

/** Parts are marked once each, on the first element of their kind (VISUAL-CONTRACT 6). */
let marked = new Set();

function part(name) {
    if (marked.has(name)) return '';
    marked.add(name);
    return ' data-part="' + name + '"';
}

function hue(label, tone, partName = null) {
    return '<span class="mi-hue' + (tone ? ' mi-hue-' + tone : '') + '"' + (partName ? part(partName) : '') + '>' + escapeHtml(label) + '</span>';
}

function sectionChip(name) {
    return hue(name, SECTION_TONES[name], 'media info section chip: ' + name.toLowerCase());
}

function resolutionChip(resolution) {
    return RESOLUTION_TONES[resolution] ? '<span class="resolution-chip resolution-chip-' + RESOLUTION_TONES[resolution] + '">' + escapeHtml(resolution) + '</span>' : '';
}

function hdrChips(stream) {
    return (stream.hdr || []).map(chip => hue(chip.label, HDR_TONES[chip.kind])).join('');
}

function channelChip(stream) {
    return hasValue(stream.channels_name) ? hue(stream.channels_name, CHANNEL_TONES[stream.channels_name]) : '';
}

function channelCount(stream) {
    return Number(stream.channels ?? String(stream.channels_display ?? '').match(/\d+/)?.[0] ?? 0) || 0;
}

/** 1 h 39 min; a legacy display string such as "1h:39m" is read the same way. */
export function duration(milliseconds, display) {
    if (hasValue(display)) {
        const match = String(display).match(/(\d+)h:(\d+)m/);
        return match ? (Number(match[1]) ? Number(match[1]) + ' h ' : '') + Number(match[2]) + ' min' : String(display);
    }
    if (!hasValue(milliseconds) || Number(milliseconds) <= 0) return null;
    const seconds = Math.floor(Number(milliseconds) / 1000), hours = Math.floor(seconds / 3600), minutes = Math.floor((seconds % 3600) / 60);
    return (hours ? hours + ' h ' : '') + minutes + ' min';
}

export function bitRate(bits, display) {
    if (hasValue(display)) return String(display).replace(/(\d) (\d)/g, '$1,$2');
    if (!hasValue(bits) || Number(bits) < 1000) return null;
    const value = Number(bits);
    return value >= 1e6 ? (value / 1e6).toFixed(1).replace(/\.0$/, '') + ' Mb/s' : Math.round(value / 1000) + ' kb/s';
}

function sampleRate(stream) {
    if (hasValue(stream.sample_rate_hz)) return Number(stream.sample_rate_hz) / 1000 + ' kHz';
    return hasValue(stream.sample_rate_display) ? String(stream.sample_rate_display).replace('.0', '') : '';
}

function aspectRatio(value) {
    if (!hasValue(value)) return null;
    return /^\d\.\d+$/.test(String(value)) ? Number(value).toFixed(2) + ':1' : String(value);
}

function frameRate(value) {
    return hasValue(value) ? Number(value).toFixed(3).replace(/\.?0+$/, '') + ' fps' : null;
}

/** The codec's plain name, with the stored format beside it when they differ: "H.265 (HEVC)". */
function codecWithFormat(stream) {
    const name = stream.codec_name;
    if (!hasValue(name)) return null;
    return hasValue(stream.format) && stream.format !== name ? name + ' (' + stream.format + ')' : name;
}

function audioName(stream) {
    const name = stream.format_name || '';
    return stream.atmos ? name.replace(/ with Atmos$/, '') : name;
}

function glance(media, resolution) {
    const streams = media.streams, video = streams.video[0], audio = streams.audio, subtitles = streams.subtitle;
    const audioLanguages = unique(audio.map(track => track.language_name));
    const subtitleLanguages = unique(subtitles.map(track => track.language_name));
    const best = [...audio].sort((a, b) => channelCount(b) - channelCount(a))[0];
    const cells = [];

    if (video) {
        const size = hasValue(video.width) && hasValue(video.height) ? video.width + ' × ' + video.height : '';
        cells.push(['Video', resolutionChip(resolution) + escapeHtml(video.codec_name || 'Unknown') + hdrChips(video),
            [size, hasValue(video.bit_depth) ? video.bit_depth + '-bit' : ''].filter(Boolean).join(' · ')]);
    } else {
        cells.push(['Video', 'None', '']);
    }

    if (best) {
        const heading = audioLanguages.length ? few(audioLanguages) : plural(audio.length, 'track');
        const atmos = audio.some(track => track.atmos) ? hue('Atmos', 'atmos') : '';
        cells.push(['Audio', escapeHtml(heading) + channelChip(best) + atmos,
            (best.format_short || '') + (audio.length > 1 ? ' · ' + plural(audio.length, 'track') : '')]);
    } else {
        cells.push(['Audio', 'None', '']);
    }

    if (subtitles.length) {
        const heading = subtitleLanguages.length === 1 ? subtitleLanguages[0] : subtitleLanguages.length > 1 ? subtitleLanguages.length + ' languages' : plural(subtitles.length, 'track');
        const detail = subtitleLanguages.length > 1 ? few(subtitleLanguages) : subtitles.length > 1 && subtitleLanguages.length === 1 ? plural(subtitles.length, 'track') : '';
        cells.push(['Subtitles', escapeHtml(heading), detail]);
    } else {
        cells.push(['Subtitles', 'None', '']);
    }

    const container = media.container || {};
    cells.push(['File', escapeHtml([container.format, duration(container.duration_ms, container.duration_display)].filter(hasValue).join(' · ') || 'Unknown'), '']);

    return '<dl class="mi-glance">' + cells.map(([name, value, detail]) => '<div><dt>' + (name === 'File' ? '<span class="mi-hue-label">File</span>' : sectionChip(name)) +
        '</dt><dd' + part('media info glance value') + '>' + value + (detail ? '<small>' + escapeHtml(detail) + '</small>' : '') + '</dd></div>').join('') + '</dl>';
}

function videoSection(stream, number, count, resolution) {
    const size = hasValue(stream.width) && hasValue(stream.height) ? escapeHtml(stream.width + ' × ' + stream.height) : null;
    const facts = [
        ['Codec', hasValue(codecWithFormat(stream)) ? escapeHtml(codecWithFormat(stream)) : null],
        ['Resolution', size === null ? null : '<span class="mi-chips">' + size + resolutionChip(resolution) + '</span>'],
        ['Aspect ratio', hasValue(aspectRatio(stream.aspect_ratio)) ? escapeHtml(aspectRatio(stream.aspect_ratio)) : null],
        ['Frame rate', hasValue(frameRate(stream.frame_rate)) ? escapeHtml(frameRate(stream.frame_rate)) : null],
        ['HDR', (stream.hdr || []).length ? '<span class="mi-chips">' + hdrChips(stream) + '</span>' : null],
        ['Bit depth', hasValue(stream.bit_depth) ? escapeHtml(stream.bit_depth + '-bit') : null],
        ['Profile', hasValue(stream.profile) ? escapeHtml(stream.profile) : null],
        // Per-stream bit rate only: a legacy row's display value is the overall bit rate.
        ['Bit rate', hasValue(bitRate(stream.bitrate_bps)) ? escapeHtml(bitRate(stream.bitrate_bps)) : null],
        ['Language', hasValue(stream.language_name) ? escapeHtml(stream.language_name) : null],
    ].filter(([, value]) => value !== null);

    return '<h3>' + sectionChip('Video') + (count > 1 ? '<small>' + number + ' of ' + count + '</small>' : '') + '</h3><dl class="mi-grid mi-grid-video">' +
        facts.map(([name, value]) => '<div><dt>' + name + '</dt><dd>' + value + '</dd></div>').join('') + '</dl>';
}

function audioSection(tracks) {
    const withTitle = tracks.some(track => hasValue(track.title) && track.title !== track.language_name);
    const withRate = tracks.some(track => hasValue(bitRate(track.bitrate_bps, track.bitrate_display)));
    const rows = tracks.map((track, index) => {
        const title = withTitle && hasValue(track.title) && track.title !== track.language_name ? '<span class="mi-sub">' + escapeHtml(track.title) + '</span>' : '';
        const name = audioName(track);
        const format = (name ? '<span class="mi-chips">' + escapeHtml(name) + (track.atmos ? hue('Atmos', 'atmos') : '') + '</span>' : '') +
            (hasValue(track.format) && track.format !== name ? '<span class="mi-sub">' + escapeHtml(track.format) + '</span>' : '');
        return '<tr><td class="mi-n"' + part('media info table cell') + '>' + (index + 1) + '</td><td class="mi-lead">' + escapeHtml(track.language_name || 'Not stated') + title + '</td><td>' + format +
            '</td><td>' + channelChip(track) + '</td>' + (withRate ? '<td class="mi-num">' + escapeHtml(bitRate(track.bitrate_bps, track.bitrate_display) || '') + '</td>' : '') +
            '<td class="mi-num">' + escapeHtml(sampleRate(track)) + '</td></tr>';
    }).join('');

    return '<h3>' + sectionChip('Audio') + '<small>' + plural(tracks.length, 'track') + '</small></h3><table class="mi-table mi-table-audio"><thead><tr><th class="mi-n"' + part('media info table header') + '>#</th>' +
        '<th class="mi-col-language">Language</th><th>Format</th><th class="mi-col-channels">Channels</th>' + (withRate ? '<th class="mi-num mi-col-rate">Bit rate</th>' : '') +
        '<th class="mi-num mi-col-rate">Sample rate</th></tr></thead><tbody>' + rows + '</tbody></table>';
}

function isBare(track) {
    return !hasValue(track.format_name) && !hasValue(track.title) && track.default !== true && track.forced !== true;
}

function subtitleSection(tracks) {
    const languages = unique(tracks.map(track => track.language_name));
    let html = '<h3>' + sectionChip('Subtitles') + '<small>' + plural(tracks.length, 'track') + (languages.length > 1 ? ' · ' + languages.length + ' languages' : '') + '</small></h3>';

    if (tracks.length > BARE_SUBTITLE_LIMIT && tracks.every(isBare)) {
        const counts = new Map();
        tracks.forEach(track => {
            const language = track.language_name || 'Not stated';
            counts.set(language, (counts.get(language) || 0) + 1);
        });
        return html + '<div class="mi-langs">' + [...counts].sort((a, b) => a[0].localeCompare(b[0]))
            .map(([language, count]) => '<div>' + escapeHtml(language) + (count > 1 ? '<span>' + count + ' tracks</span>' : '') + '</div>').join('') + '</div>';
    }

    const rows = tracks.map((track, index) => {
        const title = hasValue(track.title) && track.title !== track.language_name ? String(track.title) : '';
        const hearing = /SDH|CC|hearing/i.test(title), forced = track.forced === true || /forced/i.test(title);
        const rest = title.replace(/[[(]?\b(SDH|CC|Forced)\b[\])]?/gi, '').replace(/\s+/g, ' ').trim();
        const kinds = (track.default === true ? hue('Default', 'default') : '') + (forced ? hue('Forced', 'forced') : '') + (hearing ? hue('For hard of hearing', 'sdh') : '');
        return '<tr><td class="mi-n"' + part('media info table cell') + '>' + (index + 1) + '</td><td class="mi-lead">' + escapeHtml(track.language_name || 'Not stated') +
            (rest && rest !== track.language_name ? '<span class="mi-sub">' + escapeHtml(rest) + '</span>' : '') + '</td><td><span class="mi-chips">' + (kinds || hue('Full')) +
            '</span></td><td><span class="mi-chips">' + escapeHtml(track.format_name || '') + (track.picture ? hue('Image', 'image-subs') : '') + '</span></td></tr>';
    }).join('');

    return html + '<table class="mi-table mi-table-subtitles"><thead><tr><th class="mi-n"' + part('media info table header') + '>#</th><th class="mi-col-language">Language</th><th>Kind</th>' +
        '<th class="mi-col-format">Format</th></tr></thead><tbody>' + rows + '</tbody></table>';
}

function musicSection(tags) {
    if (!tags) return '';
    const numbered = (number, total) => !hasValue(number) ? null : hasValue(total) ? number + ' of ' + total : String(number);
    const facts = [['Album', tags.album], ['Artist', tags.artist], ['Album artist', tags.album_artist], ['Track', numbered(tags.track_number, tags.track_total)],
        ['Disc', numbered(tags.disc_number, tags.disc_total)], ['Genre', tags.genre], ['Recorded', tags.recorded_date]].filter(([, value]) => hasValue(value));
    if (!facts.length) return '';
    return '<h3>' + hue('Music') + '</h3><dl class="mi-grid">' + facts.map(([name, value]) => '<div><dt>' + name + '</dt><dd>' + escapeHtml(value) + '</dd></div>').join('') + '</dl>';
}

/** The whole block for one release's media info payload; `resolution` is the release's own ("1080p") or null. */
export function renderMediaInfo(media, resolution = null) {
    const streams = { video: [], audio: [], subtitle: [], ...(media.streams || {}) };
    const block = { ...media, streams };
    marked = new Set();
    let html = '<div class="mi-block">' + glance(block, resolution);
    html += musicSection(media.music_tags);
    streams.video.forEach((stream, index) => { html += videoSection(stream, index + 1, streams.video.length, resolution); });
    if (streams.audio.length) html += audioSection(streams.audio);
    if (streams.subtitle.length) html += subtitleSection(streams.subtitle);
    return html + '</div>';
}
