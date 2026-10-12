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

test("the TV screens' dialog renders the redesigned block", () => {
  const component = mediainfoModal();
  component.$el = { hasAttribute: () => false, querySelector: () => null };
  component.$watch = () => {};
  globalThis.document = { addEventListener() {}, removeEventListener() {} };
  globalThis.window = globalThis.window || {};
  component.init();
  assert.match(component._buildHtml({ container: {}, streams: { video: [], audio: [], subtitle: [] } }, null), /class="mi-block"/);
});

test("the TV screens' dialog leads an Audio release's audio table with Title", () => {
  const component = mediainfoModal();
  component.$el = { hasAttribute: () => false, querySelector: () => null };
  component.$watch = () => {};
  globalThis.document = { addEventListener() {}, removeEventListener() {} };
  globalThis.window = globalThis.window || {};
  component.init();
  const html = component._buildHtml({ in_audio_band: true, container: {}, music_tags: { track_title: "After the Rain" }, streams: { video: [], audio: [{ format: "FLAC", format_name: "FLAC", language_name: "English" }], subtitle: [] } }, null);

  assert.match(html, /<th class="mi-col-title">Title<\/th>/);
  assert.match(html, /<td class="mi-lead">After the Rain<\/td>/);
  assert.doesNotMatch(html, /mi-col-language/);
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
