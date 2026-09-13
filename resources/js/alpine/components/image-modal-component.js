import { modalLifecycle } from "./modal-lifecycle.js";
import { fullscreenStage } from "./fullscreen-stage.js";

/**
 * Alpine.data('imageModal') - Image preview modal (details page)
 *
 * Two stages: the modal itself (the display thumb), and the Fullscreen view of
 * the release's Full-size copy, contributed by fullscreenStage().
 */
export function imageModal() {
  return {
    ...modalLifecycle(),
    ...fullscreenStage(),

    open: false,
    imageUrl: "",
    imageTitle: "Image Preview",
    releaseName: "",
    guid: "",

    openModal(url, title, fullUrl, releaseName, guid = '') {
      this.guid = guid;
      this.imageUrl = url || "";
      this.imageTitle = title || "Image Preview";
      this.releaseName = releaseName || "";
      this.resetFullscreen(fullUrl);
      this.open = true;
    },

    hasReleaseName() {
      return this.releaseName !== "";
    },

    detailsUrl() { return '/details/' + encodeURIComponent(this.guid); },
    downloadUrl() { return '/getnzb/' + encodeURIComponent(this.guid); },

    hasNoReleaseName() {
      return !this.hasReleaseName();
    },

    close() {
      this.fullscreen = false;
      this.open = false;
    },

    init() {
      this.initModal(() => this.close(), () => this.stepBack());
      const self = this;
      window.openImageModal = function (url, title, fullUrl, releaseName, guid) {
        self.openModal(url, title, fullUrl, releaseName, guid);
      };
      window.closeImageModal = function () {
        self.close();
      };

      // Document-level click delegation for image modal triggers
      document.addEventListener("click", function (e) {
        const trigger = e.target.closest(".image-modal-trigger");
        if (trigger) {
          e.preventDefault();
          self.openModal(
            trigger.dataset.imageUrl,
            trigger.dataset.imageTitle,
            trigger.dataset.fullUrl,
            trigger.dataset.releaseDisplayName,
            trigger.dataset.guid,
          );
          return;
        }
        if (e.target.closest("[data-close-image-modal]")) {
          e.preventDefault();
          self.close();
        }
      });
    },
  };
}
