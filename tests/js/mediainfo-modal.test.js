import assert from "node:assert/strict";
import test from "node:test";

import { mediainfoModal, renderMediaInfo } from "../../resources/js/alpine/components/mediainfo-modal-component.js";

const media = {
  identity: { label: "Embedded movie title", title: "<b>Movie</b>" },
  container: {
    format: "Matroska",
    duration_ms: 6480000,
    overall_bitrate_bps: 18600000,
  },
  music_tags: null,
  streams: {
    video: [
      {
        type: "video",
        index: 0,
        id: "11",
        title: "Main <script>feature</script>",
        format: "HEVC",
        codec: "V_MPEGH/ISO/HEVC",
        codec_name: "H.265",
        hdr: [{ label: "Dolby Vision · profile 5", kind: "dv" }, { label: "HDR10+", kind: "hdr10plus" }],
        width: 3840,
        height: 2160,
        frame_rate: 23.976,
        bitrate_bps: 16200000,
        profile: "Main 10@L5.1",
        language_name: null,
      },
    ],
    audio: [
      { type: "audio", index: 0, language_name: "English", format: "E-AC-3 JOC", codec: "A_EAC3", format_name: "Dolby Digital Plus with Atmos", format_short: "E-AC-3", atmos: true, channels: 6, channels_name: "5.1", sample_rate_hz: 48000 },
      { type: "audio", index: 1, language_name: "<i>Korean</i>", format: "AAC LC", codec: "A_AAC-2", format_name: "AAC", format_short: "AAC", atmos: false, channels: 2, channels_name: "Stereo", sample_rate_hz: 48000 },
    ],
    subtitle: [
      { type: "subtitle", index: 0, id: "4", language_name: "English", title: "English SDH", format: "PGS", codec: "S_HDMV/PGS", format_name: "PGS", picture: true, forced: true, default: null },
      { type: "subtitle", index: 1, id: null, language_name: null, format: "UTF-8", codec: "S_TEXT/UTF8", format_name: "SRT", picture: false, forced: false, default: false },
    ],
  },
};

test("the block reads plainly: glance row, video grid, audio and subtitle tables, escaped, no codec ids", () => {
  const component = mediainfoModal();
  const html = component._buildHtml(media, "4K");

  assert.equal((html.match(/<dl class="mi-glance"><div>/g) || []).length, 1);
  assert.equal((html.match(/<\/dd><\/div>/g) || []).length >= 4, true);
  assert.match(html, /mi-grid mi-grid-video/);
  assert.match(html, /mi-table mi-table-audio/);
  assert.match(html, /mi-table mi-table-subtitles/);
  assert.match(html, /resolution-chip resolution-chip-4k">4K</);
  assert.match(html, /H\.265 \(HEVC\)/);
  assert.match(html, /Dolby Vision · profile 5/);
  assert.match(html, /mi-hue mi-hue-hdr10plus">HDR10\+/);
  assert.match(html, /Dolby Digital Plus<span class="mi-hue mi-hue-atmos">Atmos</);
  assert.match(html, /mi-hue mi-hue-5-1">5\.1/);
  assert.match(html, /mi-hue mi-hue-2-0">Stereo/);
  assert.match(html, /&lt;i&gt;Korean&lt;\/i&gt;/);
  assert.match(html, /mi-hue mi-hue-forced">Forced/);
  assert.match(html, /For hard of hearing/);
  assert.match(html, /PGS<span class="mi-hue mi-hue-image-subs">Image/);
  assert.match(html, /Not stated/);
  assert.match(html, /23\.976 fps/);
  assert.match(html, /Matroska · 1 h 48 min/);
  assert.doesNotMatch(html, /A_EAC3|A_AAC|S_TEXT|S_HDMV|V_MPEG|Forced: No|Default: No|Not reported|18\.6|Text</);
  assert.doesNotMatch(html, /Main <script>/);
});

test("the resolution chip follows the release, and none is drawn when the release's resolution is unknown", () => {
  const html = renderMediaInfo(media, null);
  assert.doesNotMatch(html, /resolution-chip/);
  assert.match(renderMediaInfo(media, "1080p"), /resolution-chip-1080">1080p</);
});

test("more than eight bare subtitle tracks collapse to a language grid with counts", () => {
  const bare = (language_name) => ({ type: "subtitle", language_name, format: null, format_name: null, picture: false, forced: null, default: null });
  const tracks = [...Array(6).fill("English"), "French", "German", "Spanish"].map(bare);
  const html = renderMediaInfo({ container: {}, streams: { video: [], audio: [], subtitle: tracks } });
  assert.match(html, /mi-langs/);
  assert.match(html, /English<span>6 tracks<\/span>/);
  assert.doesNotMatch(html, /mi-table-subtitles/);
  const few = renderMediaInfo({ container: {}, streams: { video: [], audio: [], subtitle: tracks.slice(0, 8) } });
  assert.match(few, /mi-table-subtitles/);
});

test("music tags keep their facts and do not invent subtitles", () => {
  const component = mediainfoModal();
  const html = component._buildHtml({
    identity: { label: "Embedded track title", title: "After the Rain" },
    container: { format: "FLAC", duration_ms: 312000, overall_bitrate_bps: 2780000 },
    music_tags: {
      album: "Night Windows",
      artist: "Northbound Quartet",
      album_artist: "Northbound Quartet",
      track_number: 3,
      track_total: 9,
      musicbrainz_release_id: "release-id",
    },
    streams: {
      video: [],
      audio: [{ type: "audio", index: 0, format: "FLAC", format_name: "FLAC", format_short: "FLAC", bit_depth: 24 }],
      subtitle: [],
    },
  });

  assert.match(html, /<dt>Album<\/dt><dd>Night Windows<\/dd>/);
  assert.match(html, /<dt>Artist<\/dt><dd>Northbound Quartet<\/dd>/);
  assert.match(html, /<dt>Album artist<\/dt><dd>Northbound Quartet<\/dd>/);
  assert.match(html, /<dt>Track<\/dt><dd>3 of 9<\/dd>/);
  assert.doesNotMatch(html, /mi-table-subtitles|file-completeness|probe history/i);
});

test("rapid switching ignores a late response and updates heading with the matching release", async () => {
  const pending = [];
  globalThis.fetch = (url) =>
    new Promise((resolve) => pending.push({ url, resolve }));
  const component = mediainfoModal();
  component.$refs = { content: { innerHTML: "" } };

  component.show(1, "First Release");
  component.show(2, "Second Release");
  assert.equal(component.releaseName, "Second Release");
  assert.equal(component.$refs.content.innerHTML, "");

  pending[1].resolve({
    ok: true,
    json: async () => ({ release_name: "Second Resolved", media }),
  });
  await new Promise((resolve) => setTimeout(resolve, 0));
  assert.equal(component.releaseName, "Second Resolved");
  const secondHtml = component.$refs.content.innerHTML;

  pending[0].resolve({
    ok: true,
    json: async () => ({ release_name: "Stale First", media: null }),
  });
  await new Promise((resolve) => setTimeout(resolve, 0));
  assert.equal(component.releaseName, "Second Resolved");
  assert.equal(component.$refs.content.innerHTML, secondHtml);
  assert.equal(component.loading, false);
});

test("empty and failed responses retain defensive feedback", async () => {
  const responses = [
    { ok: true, json: async () => ({ release_name: "Empty", media: null }) },
    { ok: false },
  ];
  globalThis.fetch = async () => responses.shift();
  const component = mediainfoModal();
  component.$refs = { content: { innerHTML: "" } };

  component.show(1, "Empty");
  await new Promise((resolve) => setTimeout(resolve, 0));
  assert.match(component.$refs.content.innerHTML, /No media information available/);

  component.show(2, "Failed");
  await new Promise((resolve) => setTimeout(resolve, 0));
  assert.match(component.$refs.content.innerHTML, /Unable to load media information/);
});
