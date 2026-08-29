import { fullscreenStage } from "./fullscreen-stage.js";

/**
 * Alpine.data('imageModal') - Image preview modal (details page)
 *
 * Two stages: the modal itself (the display thumb), and the Fullscreen view of
 * the release's Full-size copy, contributed by fullscreenStage().
 */
export function imageModal() {
  return {
    ...fullscreenStage(),

    open: false,
    imageUrl: "",
    imageTitle: "Image Preview",
    releaseName: "",

    openModal(url, title, fullUrl, releaseName) {
      this.imageUrl = url || "";
      this.imageTitle = title || "Image Preview";
      this.releaseName = releaseName || "";
      this.resetFullscreen(fullUrl);
      this.open = true;
    },

    hasReleaseName() {
      return this.releaseName !== "";
    },

    hasNoReleaseName() {
      return !this.hasReleaseName();
    },

    close() {
      this.fullscreen = false;
      this.open = false;
    },

    init() {
      const self = this;
      window.openImageModal = function (url, title, fullUrl, releaseName) {
        self.openModal(url, title, fullUrl, releaseName);
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
          );
          return;
        }
        if (e.target.closest("[data-close-image-modal]")) {
          e.preventDefault();
          self.close();
        }
      });

      document.addEventListener("keydown", function (e) {
        if (e.key === "Escape" && self.open) self.stepBack();
      });
    },
  };
}
