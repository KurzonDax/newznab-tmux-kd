import assert from "node:assert/strict";
import test from "node:test";

import { mediainfoModal } from "../../resources/js/alpine/components/mediainfo-modal-component.js";

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
        width: 3840,
        height: 2160,
        frame_rate: 23.976,
        bitrate_bps: 16200000,
        profile: "Main 10@L5.1",
      },
    ],
    audio: [],
    subtitle: [
      { type: "subtitle", index: 0, id: "4", language: "English", forced: true, default: null },
      { type: "subtitle", index: 1, id: null, language: null, forced: false, default: false },
    ],
  },
};

test("grouped layout escapes names, labels technical values, and renders nullable subtitle dispositions", () => {
  const component = mediainfoModal();
  const html = component._buildHtml(media);

  assert.match(html, /&lt;b&gt;Movie&lt;\/b&gt;/);
  assert.match(html, /Main &lt;script&gt;feature&lt;\/script&gt;/);
  assert.match(html, /Resolution:<\/span> <b>3840 × 2160<\/b>/);
  assert.match(html, /Frame rate:<\/span> <b>23\.976 fps<\/b>/);
  assert.match(html, /Forced:<\/span> <b>Yes<\/b>/);
  assert.match(html, /Forced:<\/span> <b>No<\/b>/);
  assert.match(html, /Default:<\/span> <b>Not reported<\/b>/);
  assert.match(html, /<details/);
  assert.match(html, /Additional details/);
  assert.doesNotMatch(html, /diagnostic|source_completeness|captured_at/);
});

test("music tags use the shared card, retain distinct artist meanings, and do not invent subtitles", () => {
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
      audio: [{ type: "audio", index: 0, format: "FLAC", bit_depth: 24 }],
      subtitle: [],
    },
  });

  assert.match(html, /mediainfo-track mediainfo-music-tags/);
  assert.match(html, /Artist:<\/span> <b>Northbound Quartet<\/b>/);
  assert.match(html, /Album artist:<\/span> <b>Northbound Quartet<\/b>/);
  assert.match(html, /Track:<\/span> <b>3 of 9<\/b>/);
  assert.match(html, /MusicBrainz release ID/);
  assert.doesNotMatch(html, /Subtitles|file-completeness|probe history/i);
});

test("the site dialog shows the plain names beside the stored format, never the codec id when a name exists", () => {
  const html = mediainfoModal()._buildHtml({
    identity: { label: "Container", title: null },
    container: { format: "Matroska" },
    streams: {
      video: [{ type: "video", index: 0, format: "HEVC", codec: "V_MPEGH/ISO/HEVC", codec_name: "H.265" }],
      audio: [{ type: "audio", index: 0, format: "E-AC-3", codec: "A_EAC3", format_name: "Dolby Digital Plus", language: "en", language_name: "English", channels: 6, channels_name: "5.1" }],
      subtitle: [],
    },
  });

  assert.match(html, /H\.265 · HEVC/);
  assert.match(html, /Dolby Digital Plus · E-AC-3/);
  assert.match(html, /Language:<\/span> <b>English<\/b>/);
  assert.match(html, /Channels:<\/span> <b>5\.1<\/b>/);
  assert.doesNotMatch(html, /V_MPEGH|A_EAC3/);
});

test("the TV screens' dialog renders the redesigned block", () => {
  const component = mediainfoModal();
  component.$el = { hasAttribute: (name) => name === "data-media-info-block", querySelector: () => null };
  component.$watch = () => {};
  globalThis.document = { addEventListener() {}, removeEventListener() {} };
  globalThis.window = globalThis.window || {};
  component.init();
  assert.match(component._buildHtml({ container: {}, streams: { video: [], audio: [], subtitle: [] } }, null), /class="mi-block"/);
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
