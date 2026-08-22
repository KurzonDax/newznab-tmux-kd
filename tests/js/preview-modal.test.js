import assert from "node:assert/strict";
import test from "node:test";

import { previewModal } from "../../resources/js/alpine/components/preview-modal-component.js";

test("preview chip title and resolved image URL are shown in the browse modal", () => {
  let clickHandler;
  const preview = {
    dataset: {
      guid: "audio-guid",
      imageUrl: "/covers/audiosample/audio-guid_spectrum.png",
      imageTitle: "Spectrogram",
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

  assert.equal(component.title, "Spectrogram");
  assert.equal(
    component.imageUrl,
    "/covers/audiosample/audio-guid_spectrum.png",
  );
  assert.equal(component.open, true);
});
