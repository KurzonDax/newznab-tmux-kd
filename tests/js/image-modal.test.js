import assert from "node:assert/strict";
import test from "node:test";

import { imageModal } from "../../resources/js/alpine/components/image-modal-component.js";

test("opening images updates and clears the release name", () => {
  const component = imageModal();
  const imageUrl = "/covers/preview/shared_thumb.webp";

  component.openModal(
    imageUrl,
    "Preview Image",
    undefined,
    "First Release 2026.08.29 H.264 MKV",
  );
  assert.equal(component.releaseName, "First Release 2026.08.29 H.264 MKV");
  assert.equal(component.hasReleaseName(), true);
  assert.equal(component.hasNoReleaseName(), false);

  component.openModal(imageUrl, "Preview Image");
  assert.equal(component.releaseName, "");
  assert.equal(component.hasReleaseName(), false);
  assert.equal(component.hasNoReleaseName(), true);
});

test("an image trigger passes its release display name to the modal", () => {
  let clickHandler;
  const trigger = {
    dataset: {
      imageUrl: "/covers/sample/details-guid_thumb.webp",
      imageTitle: "Sample Image",
      fullUrl: "/covers/sample/details-guid.webp",
      releaseDisplayName: "Readable Details Release MKV",
    },
  };

  globalThis.window = {};
  globalThis.document = {
    addEventListener(type, handler) {
      if (type === "click") clickHandler = handler;
    },
  };

  const component = imageModal();
  component.init();
  clickHandler({
    preventDefault() {},
    target: {
      closest(selector) {
        return selector === ".image-modal-trigger" ? trigger : null;
      },
    },
  });

  assert.equal(component.imageUrl, "/covers/sample/details-guid_thumb.webp");
  assert.equal(component.imageTitle, "Sample Image");
  assert.equal(component.fullUrl, "/covers/sample/details-guid.webp");
  assert.equal(component.releaseName, "Readable Details Release MKV");
  assert.equal(component.open, true);
});
