import { modalLifecycle } from "./modal-lifecycle.js";
import { fullscreenStage } from "./fullscreen-stage.js";

const prefetchedUrls = new Set();

function buildImageUrl(guid, type) {
  return "/covers/" + (type || "preview") + "/" + guid + "_thumb.webp";
}

function prefetchImage(guid, type, resolvedUrl) {
  const url = resolvedUrl || buildImageUrl(guid, type);
  if (!prefetchedUrls.has(url)) {
    const img = new Image();
    img.src = url;
    prefetchedUrls.add(url);
  }
}

function imagePrefetchPayload(element) {
  const imageUrl = element.dataset.imageUrl;
  if (
    !imageUrl &&
    (element.classList.contains("audio-preview-badge") ||
      element.dataset.videoUrl)
  ) {
    return null;
  }

  return {
    guid: element.dataset.guid,
    type: element.classList.contains("sample-badge") ? "sample" : "preview",
    imageUrl,
  };
}

export function previewModal() {
  return {
    ...fullscreenStage(),
    ...modalLifecycle(),

    open: false,
    title: "Preview Image",
    releaseName: "",
    imageUrl: "",
    imageError: false,
    imageLoaded: false,
    audioUrl: "",
    audioType: "",
    audioMeta: "",
    videoUrl: "",
    videoType: "",
    videoPlaying: false,
    guid: '',
    previewKind: 'preview',
    audioTitle: '',
    audioArtist: '',
    audioArtwork: '',

    show(guid, type, resolvedUrl, title, audio, video, fullUrl, releaseName) {
      this.guid = guid;
      this.releaseAudio();
      this.releaseVideo();
      // Offered only where a Full-size copy is on disk (ADR 0012); the trigger
      // omits the attribute entirely for the back catalog.
      this.resetFullscreen(fullUrl);

      type = type || "preview";
      this.title =
        title || (type === "sample" ? "Sample Image" : "Preview Image");
      this.releaseName = releaseName || "";
      const hasAudioPreview = Boolean(audio?.url);
      const hasVideoPreview = Boolean(video?.url);
      const newUrl =
        resolvedUrl ||
        (hasAudioPreview || hasVideoPreview ? "" : buildImageUrl(guid, type));

      this.audioUrl = audio?.url || "";
      this.audioType = audio?.type || "";
      this.audioMeta = audio?.meta || "";
      this.audioTitle = audio?.title || releaseName || 'Audio preview';
      this.audioArtist = audio?.artist || '';
      this.audioArtwork = audio?.artwork || '';
      this.videoUrl = video?.url || "";
      this.videoType = video?.type || "";
      this.previewKind = hasAudioPreview ? 'audio' : hasVideoPreview ? 'video' : type;
      if (hasVideoPreview) this.title = 'Video preview';

      if (this.imageUrl === newUrl) {
        this.open = true;
        return;
      }

      this.imageUrl = newUrl;
      this.imageError = false;
      this.imageLoaded = prefetchedUrls.has(newUrl);
      this.open = true;
    },

    onImageError() {
      this.imageError = true;
    },

    kindIcon() {
      return { audio: 'fa-headphones', video: 'fa-video', sample: 'fa-images', preview: 'fa-image' }[this.previewKind];
    },

    detailsUrl() { return '/details/' + encodeURIComponent(this.guid); },
    downloadUrl() { return '/getnzb/' + encodeURIComponent(this.guid); },

    onImageLoad() {
      this.imageLoaded = true;
    },

    close() {
      this.releaseAudio();
      this.releaseVideo();
      this.fullscreen = false;
      this.open = false;
    },

    // No bytes are fetched until play is pressed: the <video> element has no
    // src until this runs, and it replaces the image in the same space.
    playVideo() {
      this.videoPlaying = true;
      const player = this.$refs?.videoPlayer;
      if (player) {
        const source = player.querySelector("source");
        if (source) {
          source.setAttribute("src", this.videoUrl);
          source.setAttribute("type", this.videoType);
        }
        player.setAttribute("src", this.videoUrl);
        player.load();
        player.play?.();
      }
    },

    releaseVideo() {
      const player = this.$refs?.videoPlayer;
      if (player) {
        player.pause();
        player.removeAttribute("src");
        player.querySelector("source")?.removeAttribute("src");
        player.load();
      }

      this.videoPlaying = false;
      this.videoUrl = "";
      this.videoType = "";
    },

    releaseAudio() {
      const player = this.$refs?.audioPlayer;
      if (player) {
        player.pause();
        player.removeAttribute("src");
        player.querySelector("source")?.removeAttribute("src");
        player.load();
      }

      this.audioUrl = "";
      this.audioType = "";
      this.audioMeta = "";
    },

    errorMessage() {
      return this.title.replace(" Image", "") + " image not available";
    },

    hasReleaseName() {
      return this.releaseName !== "";
    },

    hasNoReleaseName() {
      return !this.hasReleaseName();
    },

    init() {
      this.initModal(() => this.close(), () => this.stepBack());
      const self = this;
      window.showPreviewImage = function (guid, type) {
        self.show(guid, type);
      };
      window.closePreviewModal = function () {
        self.close();
      };

      document.addEventListener("click", function (e) {
        const preview = e.target.closest(".preview-badge");
        if (preview) {
          e.preventDefault();
          self.show(
            preview.dataset.guid,
            "preview",
            preview.dataset.imageUrl,
            preview.dataset.imageTitle,
            preview.dataset.audioUrl
              ? {
                  url: preview.dataset.audioUrl,
                  type: preview.dataset.audioType,
                  meta: preview.dataset.audioMeta,
                  title: preview.dataset.audioTitle,
                  artist: preview.dataset.audioArtist,
                  artwork: preview.dataset.audioArtwork,
                }
              : undefined,
            preview.dataset.videoUrl
              ? {
                  url: preview.dataset.videoUrl,
                  type: preview.dataset.videoType,
                }
              : undefined,
            preview.dataset.fullUrl,
            preview.dataset.releaseDisplayName,
          );
          return;
        }
        const sample = e.target.closest(".sample-badge");
        if (sample) {
          e.preventDefault();
          self.show(
            sample.dataset.guid,
            "sample",
            sample.dataset.imageUrl,
            undefined,
            undefined,
            undefined,
            sample.dataset.fullUrl,
            sample.dataset.releaseDisplayName,
          );
          return;
        }
        if (e.target.closest("[data-close-preview-modal]")) {
          e.preventDefault();
          self.close();
        }
      });

      // Prefetch on hover so the image is cached before click
      document.addEventListener("mouseover", function (e) {
        const preview = e.target.closest(".preview-badge");
        if (preview) {
          const payload = imagePrefetchPayload(preview);
          if (payload) {
            prefetchImage(payload.guid, payload.type, payload.imageUrl);
          }
          return;
        }
        const sample = e.target.closest(".sample-badge");
        if (sample) {
          const payload = imagePrefetchPayload(sample);
          if (payload) {
            prefetchImage(payload.guid, payload.type, payload.imageUrl);
          }
        }
      });

      // Prefetch images for badges visible in the viewport during idle time
      if ("IntersectionObserver" in window) {
        const observer = new IntersectionObserver(
          function (entries) {
            entries.forEach(function (entry) {
              if (entry.isIntersecting) {
                const el = entry.target;
                const payload = imagePrefetchPayload(el);
                if (!payload) {
                  observer.unobserve(el);
                  return;
                }
                if (typeof requestIdleCallback === "function") {
                  requestIdleCallback(function () {
                    prefetchImage(payload.guid, payload.type, payload.imageUrl);
                  });
                } else {
                  setTimeout(function () {
                    prefetchImage(payload.guid, payload.type, payload.imageUrl);
                  }, 200);
                }
                observer.unobserve(el);
              }
            });
          },
          { rootMargin: "200px" },
        );

        document
          .querySelectorAll(".sample-badge, .preview-badge")
          .forEach(function (el) {
            observer.observe(el);
          });
      }
    },
  };
}
