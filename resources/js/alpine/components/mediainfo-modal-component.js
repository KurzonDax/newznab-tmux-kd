import { modalLifecycle } from "./modal-lifecycle.js";
import { renderMediaInfo } from "./media-info-block.js";
export { renderMediaInfo };

export function mediainfoModal() {
  return {
    ...modalLifecycle(),
    open: false,
    loading: false,
    releaseName: "",
    requestVersion: 0,

    _setContent(html) {
      if (this.$refs?.content) this.$refs.content.innerHTML = html;
    },

    show(releaseId, releaseName, trigger = null) {
      const version = ++this.requestVersion;
      this.releaseName = releaseName || "";
      this.open = true;
      this.loading = true;
      this._setContent("");

      fetch("/release/" + encodeURIComponent(releaseId) + "/mediainfo", { headers: { Accept: "application/json" } })
        .then((response) => {
          if (!response.ok) throw new Error("Unable to load media information");
          return response.json();
        })
        .then((data) => {
          if (version !== this.requestVersion) return;
          this.releaseName = data.release_name || this.releaseName;
          this._setContent(data.media ? this._buildHtml(data.media, data.resolution ?? null) : '<p class="mediainfo-message">No media information available</p>');
          this.loading = false;
        })
        .catch(() => {
          if (version !== this.requestVersion) return;
          this._setContent('<div class="mediainfo-message mediainfo-error"><i class="fas fa-exclamation-circle" aria-hidden="true"></i><p>Unable to load media information</p></div>');
          this.loading = false;
        });
    },

    close() {
      this.requestVersion++;
      this.open = false;
      this.loading = false;
      this.releaseName = "";
      this._setContent("");
    },

    _buildHtml: renderMediaInfo,

    init() {
      this.initModal();
      const self = this;
      window.showMediainfo = (id, name) => self.show(id, name);
      window.closeMediainfoModal = () => self.close();
      document.addEventListener("click", (event) => {
        const badge = event.target.closest(".mediainfo-badge");
        if (badge) {
          event.preventDefault();
          self.show(badge.dataset.releaseId, badge.dataset.releaseDisplayName, badge);
        }
      });
    },
  };
}
