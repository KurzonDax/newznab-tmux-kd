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
