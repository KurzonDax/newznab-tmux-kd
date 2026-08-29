import assert from "node:assert/strict";
import test from "node:test";

import { previewModal } from "../../resources/js/alpine/components/preview-modal-component.js";

function installPrefetchEnvironment(preview, createdImageUrls) {
  const handlers = {};
  let observerCallback;

  globalThis.Image = class {
    set src(url) {
      createdImageUrls.push(url);
    }
  };
  globalThis.window = { IntersectionObserver: true };
  globalThis.document = {
    addEventListener(type, handler) {
      handlers[type] = handler;
    },
    querySelectorAll() {
      return [preview];
    },
  };
  globalThis.IntersectionObserver = class {
    constructor(callback) {
      observerCallback = callback;
    }

    observe() {}

    unobserve() {}
  };
  globalThis.requestIdleCallback = (callback) => callback();

  previewModal().init();

  return {
    hover() {
      handlers.mouseover({
        target: {
          closest(selector) {
            return selector === ".preview-badge" ? preview : null;
          },
        },
      });
    },
    intersect() {
      observerCallback([{ isIntersecting: true, target: preview }]);
    },
  };
}

test("show accepts an audio payload and keeps image-only previews unchanged", () => {
  const component = previewModal();

  component.show(
    "audio-guid",
    "preview",
    "/covers/audiosample/audio-guid_spectrum.png",
    "Audio Preview",
    {
      url: "/preview/audio/audio-guid",
      type: "audio/mpeg",
      meta: "30s · MP3 · stream copy",
    },
  );

  assert.equal(component.title, "Audio Preview");
  assert.equal(component.audioUrl, "/preview/audio/audio-guid");
  assert.equal(component.audioType, "audio/mpeg");
  assert.equal(component.audioMeta, "30s · MP3 · stream copy");
  assert.equal(
    component.imageUrl,
    "/covers/audiosample/audio-guid_spectrum.png",
  );

  component.show(
    "video-guid",
    "preview",
    "/covers/preview/video-guid_thumb.webp",
    "Preview Image",
  );

  assert.equal(component.audioUrl, "");
  assert.equal(component.audioType, "");
  assert.equal(component.audioMeta, "");
  assert.equal(component.title, "Preview Image");
});

test("image-only previews keep the conventional URL fallback", () => {
  const component = previewModal();

  component.show("video-fallback-guid", "preview", "", "Preview Image");

  assert.equal(
    component.imageUrl,
    "/covers/preview/video-fallback-guid_thumb.webp",
  );
});

test("reopening the same failed image preserves its error state", () => {
  const component = previewModal();

  component.show(
    "failed-image-guid",
    "preview",
    "/covers/preview/failed-image-guid_thumb.webp",
    "Preview Image",
  );
  component.onImageError();
  component.show(
    "failed-image-guid",
    "preview",
    "/covers/preview/failed-image-guid_thumb.webp",
    "Preview Image",
  );

  assert.equal(component.imageError, true);
  assert.equal(component.imageLoaded, false);
  assert.equal(component.open, true);
});

test("reopening the same image updates and clears the release name", () => {
  const component = previewModal();
  const imageUrl = "/covers/preview/shared_thumb.webp";

  component.show(
    "first-guid",
    "preview",
    imageUrl,
    "Preview Image",
    undefined,
    undefined,
    undefined,
    "First Release 2026.08.29 H.264 MKV",
  );
  component.show(
    "second-guid",
    "preview",
    imageUrl,
    "Preview Image",
    undefined,
    undefined,
    undefined,
    "Second Release v1.2.3 DDP5.1 MKV",
  );

  assert.equal(component.releaseName, "Second Release v1.2.3 DDP5.1 MKV");

  component.show("legacy-guid", "preview", imageUrl, "Preview Image");

  assert.equal(component.releaseName, "");
});

test("close and opening another preview stop and release the audio element", () => {
  let pauseCount = 0;
  const removedAudioAttributes = [];
  const removedSourceAttributes = [];
  const component = previewModal();
  component.$refs = {
    audioPlayer: {
      pause() {
        pauseCount += 1;
      },
      removeAttribute(attribute) {
        removedAudioAttributes.push(attribute);
      },
      querySelector(selector) {
        assert.equal(selector, "source");

        return {
          removeAttribute(attribute) {
            removedSourceAttributes.push(attribute);
          },
        };
      },
      load() {},
    },
  };

  component.show("first-guid", "preview", "", "Audio Preview", {
    url: "/preview/audio/first-guid",
    type: "audio/mpeg",
    meta: "30s · MP3",
  });
  pauseCount = 0;
  removedAudioAttributes.length = 0;
  removedSourceAttributes.length = 0;

  component.close();
  component.show("second-guid", "preview", "", "Audio Preview", {
    url: "/preview/audio/second-guid",
    type: "audio/flac",
    meta: "30s · FLAC",
  });

  assert.equal(pauseCount, 2);
  assert.deepEqual(removedAudioAttributes, ["src", "src"]);
  assert.deepEqual(removedSourceAttributes, ["src", "src"]);
  assert.equal(component.audioUrl, "/preview/audio/second-guid");
});

test("preview chip passes its audio and image data to the browse modal", () => {
  let clickHandler;
  const preview = {
    dataset: {
      guid: "audio-guid",
      releaseDisplayName: "Readable Audio Release FLAC",
      imageUrl: "/covers/audiosample/audio-guid_spectrum.png",
      imageTitle: "Audio Preview",
      audioUrl: "/preview/audio/audio-guid",
      audioType: "audio/mpeg",
      audioMeta: "30s · MP3 · stream copy",
    },
  };

  globalThis.window = {};
  globalThis.document = {
    addEventListener(type, handler) {
      if (type === "click") clickHandler = handler;
    },
    querySelectorAll() {
      return [];
    },
  };

  const component = previewModal();
  component.init();
  clickHandler({
    preventDefault() {},
    target: {
      closest(selector) {
        return selector === ".preview-badge" ? preview : null;
      },
    },
  });

  assert.equal(component.title, "Audio Preview");
  assert.equal(component.releaseName, "Readable Audio Release FLAC");
  assert.equal(component.audioUrl, "/preview/audio/audio-guid");
  assert.equal(component.audioType, "audio/mpeg");
  assert.equal(component.audioMeta, "30s · MP3 · stream copy");
  assert.equal(
    component.imageUrl,
    "/covers/audiosample/audio-guid_spectrum.png",
  );
  assert.equal(component.open, true);
});

test("hover and viewport prefetch ignore an audio-only preview URL", () => {
  const createdImageUrls = [];
  let audioUrlReads = 0;
  const preview = {
    classList: {
      contains(className) {
        return className === "audio-preview-badge";
      },
    },
    dataset: {
      guid: "audio-only-guid",
      imageUrl: "",
    },
  };
  Object.defineProperty(preview.dataset, "audioUrl", {
    get() {
      audioUrlReads += 1;

      return "/preview/audio/audio-only-guid";
    },
  });

  const environment = installPrefetchEnvironment(preview, createdImageUrls);
  environment.hover();
  environment.intersect();

  assert.equal(audioUrlReads, 0);
  assert.deepEqual(createdImageUrls, []);
});

function fakeVideoPlayer(state) {
  return {
    attributes: {},
    pause() {
      state.pauseCount += 1;
    },
    setAttribute(attribute, value) {
      this.attributes[attribute] = value;
    },
    removeAttribute(attribute) {
      delete this.attributes[attribute];
      state.removedVideoAttributes.push(attribute);
    },
    querySelector(selector) {
      assert.equal(selector, "source");

      return {
        setAttribute(attribute, value) {
          state.sourceAttributes[attribute] = value;
        },
        removeAttribute(attribute) {
          delete state.sourceAttributes[attribute];
          state.removedSourceAttributes.push(attribute);
        },
      };
    },
    load() {
      state.loadCount += 1;
    },
    play() {
      state.playCount += 1;
    },
  };
}

test("show accepts a video payload and play swaps the image for the video stream", () => {
  const state = {
    pauseCount: 0,
    loadCount: 0,
    playCount: 0,
    sourceAttributes: {},
    removedVideoAttributes: [],
    removedSourceAttributes: [],
  };
  const component = previewModal();
  component.$refs = { videoPlayer: fakeVideoPlayer(state) };

  component.show(
    "clip-guid",
    "preview",
    "/covers/preview/clip-guid_thumb.webp",
    "Preview Image",
    undefined,
    { url: "/preview/video/clip-guid", type: "video/mp4" },
  );

  assert.equal(component.videoUrl, "/preview/video/clip-guid");
  assert.equal(component.videoType, "video/mp4");
  assert.equal(component.videoPlaying, false);
  assert.equal(component.imageUrl, "/covers/preview/clip-guid_thumb.webp");
  assert.equal(
    component.$refs.videoPlayer.attributes.src,
    undefined,
    "No src — so no bytes fetched — until play is pressed.",
  );

  component.playVideo();

  assert.equal(component.videoPlaying, true);
  assert.equal(
    component.$refs.videoPlayer.attributes.src,
    "/preview/video/clip-guid",
  );
  assert.equal(state.sourceAttributes.src, "/preview/video/clip-guid");
  assert.equal(state.sourceAttributes.type, "video/mp4");
  assert.equal(state.playCount, 1);
});

test("close tears the video stream down", () => {
  const state = {
    pauseCount: 0,
    loadCount: 0,
    playCount: 0,
    sourceAttributes: {},
    removedVideoAttributes: [],
    removedSourceAttributes: [],
  };
  const component = previewModal();
  component.$refs = { videoPlayer: fakeVideoPlayer(state) };

  component.show("clip-guid", "preview", "", "Preview Image", undefined, {
    url: "/preview/video/clip-guid",
    type: "video/mp4",
  });
  component.playVideo();
  state.pauseCount = 0;
  state.removedVideoAttributes.length = 0;
  state.removedSourceAttributes.length = 0;

  component.close();

  assert.equal(state.pauseCount, 1);
  assert.ok(state.removedVideoAttributes.includes("src"));
  assert.ok(state.removedSourceAttributes.includes("src"));
  assert.equal(component.videoPlaying, false);
  assert.equal(component.videoUrl, "");
  assert.equal(component.videoType, "");
});

test("preview chip passes its video data to the browse modal", () => {
  let clickHandler;
  const preview = {
    dataset: {
      guid: "clip-guid",
      imageUrl: "/covers/preview/clip-guid_thumb.webp",
      imageTitle: "Preview Image",
      videoUrl: "/preview/video/clip-guid",
      videoType: "video/mp4",
    },
  };

  globalThis.window = {};
  globalThis.document = {
    addEventListener(type, handler) {
      if (type === "click") clickHandler = handler;
    },
    querySelectorAll() {
      return [];
    },
  };

  const component = previewModal();
  component.init();
  clickHandler({
    preventDefault() {},
    target: {
      closest(selector) {
        return selector === ".preview-badge" ? preview : null;
      },
    },
  });

  assert.equal(component.videoUrl, "/preview/video/clip-guid");
  assert.equal(component.videoType, "video/mp4");
  assert.equal(component.imageUrl, "/covers/preview/clip-guid_thumb.webp");
  assert.equal(component.open, true);
});

test("hover and viewport prefetch ignore a video-only chip with no image", () => {
  const createdImageUrls = [];
  const preview = {
    classList: {
      contains() {
        return false;
      },
    },
    dataset: {
      guid: "video-only-guid",
      imageUrl: "",
      videoUrl: "/preview/video/video-only-guid",
    },
  };

  const environment = installPrefetchEnvironment(preview, createdImageUrls);
  environment.hover();
  environment.intersect();

  assert.deepEqual(createdImageUrls, []);
});

test("image-only badges keep conventional hover and viewport prefetch", () => {
  const createdImageUrls = [];
  const preview = {
    classList: {
      contains() {
        return false;
      },
    },
    dataset: {
      guid: "video-prefetch-guid",
      imageUrl: "",
    },
  };

  const environment = installPrefetchEnvironment(preview, createdImageUrls);
  environment.hover();
  environment.intersect();

  assert.deepEqual(createdImageUrls, [
    "/covers/preview/video-prefetch-guid_thumb.webp",
  ]);
});
