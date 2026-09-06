function escapeHtml(value) {
  const map = { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#039;" };
  return String(value).replace(/[&<>"']/g, (character) => map[character]);
}

function hasValue(value) {
  return value !== null && value !== undefined && value !== "";
}

function duration(milliseconds) {
  if (!hasValue(milliseconds)) return null;
  const seconds = Math.max(0, Math.round(Number(milliseconds) / 1000));
  const hours = Math.floor(seconds / 3600);
  const minutes = Math.floor((seconds % 3600) / 60);
  const remainder = seconds % 60;
  const parts = [];
  if (hours) parts.push(hours + " h");
  if (minutes) parts.push(minutes + " min");
  if (remainder || parts.length === 0) parts.push(remainder + " s");
  return parts.join(" ");
}

function bitrate(bits) {
  if (!hasValue(bits)) return null;
  const value = Number(bits);
  if (value >= 1000000) {
    return (value / 1000000).toLocaleString(undefined, { maximumFractionDigits: 2 }) + " Mb/s";
  }
  return Math.round(value / 1000).toLocaleString() + " kb/s";
}

function sampleRate(hertz) {
  if (!hasValue(hertz)) return null;
  return (Number(hertz) / 1000).toLocaleString(undefined, {
    minimumFractionDigits: 1,
    maximumFractionDigits: 3,
  }) + " kHz";
}

function fact(label, value) {
  if (!hasValue(value)) return "";
  return '<span class="mediainfo-fact"><span class="mediainfo-fact-label">' +
    escapeHtml(label) + ':</span> <b>' + escapeHtml(value) + "</b></span>";
}

function flagFact(label, value, emphasized = false) {
  if (!hasValue(value)) return "";
  const className = emphasized ? "mediainfo-fact mediainfo-forced-yes" : "mediainfo-fact";
  return '<span class="' + className + '"><span class="mediainfo-fact-label">' +
    escapeHtml(label) + ':</span> <b>' + escapeHtml(value) + "</b></span>";
}

function facts(entries) {
  const content = entries.map(([label, value]) => fact(label, value)).join("");
  return content ? '<div class="mediainfo-facts">' + content + "</div>" : "";
}

function codec(stream) {
  const values = [stream.format, stream.codec].filter(hasValue);
  return [...new Set(values)].join(" · ");
}

function identifier(stream) {
  if (hasValue(stream.id)) {
    return "#" + stream.id + (hasValue(stream.order) ? " · order " + stream.order : "");
  }
  if (hasValue(stream.order)) return "Order " + stream.order;
  return "#" + (Number(stream.index || 0) + 1);
}

function streamCard(type, stream) {
  const title = stream.title || (type === "subtitle" ? "Subtitle" : type === "video" ? "Video" : "Audio");
  let primary = [];
  if (type === "video") {
    primary = [
      ["Language", stream.language],
      ["Resolution", hasValue(stream.width) && hasValue(stream.height) ? stream.width + " × " + stream.height : null],
      ["Aspect ratio", stream.aspect_ratio],
      ["Frame rate", hasValue(stream.frame_rate) ? stream.frame_rate + " fps" : null],
      ["Bit rate", stream.bitrate_display || bitrate(stream.bitrate_bps)],
    ];
  } else if (type === "audio") {
    primary = [
      ["Language", stream.language],
      ["Channels", stream.channel_layout || stream.channels_display || (hasValue(stream.channels) ? stream.channels + " channels" : null)],
      ["Sample rate", stream.sample_rate_display || sampleRate(stream.sample_rate_hz)],
      ["Bit depth", hasValue(stream.bit_depth) ? stream.bit_depth + "-bit" : null],
      ["Bit rate", stream.bitrate_display || bitrate(stream.bitrate_bps)],
      ["Duration", stream.duration_display || duration(stream.duration_ms)],
    ];
  } else {
    primary = [["Language", stream.language]];
  }

  let flags = "";
  if (type === "subtitle") {
    const disposition = (value) => value === null || value === undefined ? "Not reported" : value ? "Yes" : "No";
    flags = '<div class="mediainfo-flags">' +
      flagFact("Forced", disposition(stream.forced), stream.forced === true) +
      flagFact("Default", disposition(stream.default)) + "</div>";
  }

  return '<article class="mediainfo-track"><div class="mediainfo-track-head"><span class="mediainfo-id">' +
    escapeHtml(identifier(stream)) + '</span><span class="mediainfo-track-title">' + escapeHtml(title) +
    '</span><span class="mediainfo-codec">' + escapeHtml(codec(stream)) + "</span></div>" +
    facts(primary) + flags + "</article>";
}

function section(name, icon, type, streams) {
  if (!streams.length) return "";
  const count = streams.length + (streams.length === 1 ? " stream" : " streams");
  return '<section class="mediainfo-section"><h4><i class="fas ' + icon +
    '" aria-hidden="true"></i><span>' + escapeHtml(name) + '</span><span class="mediainfo-count">' +
    count + "</span></h4>" + streams.map((stream) => streamCard(type, stream)).join("") + "</section>";
}

function musicTags(tags) {
  if (!tags) return "";
  const numbered = (number, total) => !hasValue(number) ? null : hasValue(total) ? number + " of " + total : number;
  const content = facts([["Album", tags.album]]) + facts([["Artist", tags.artist]]) +
    facts([["Album artist", tags.album_artist]]) +
    facts([["Track", numbered(tags.track_number, tags.track_total)], ["Disc", numbered(tags.disc_number, tags.disc_total)]]) +
    facts([["Genre", tags.genre], ["Recorded date", tags.recorded_date]]);
  if (!content) return "";
  return '<section class="mediainfo-section"><h4><i class="fas fa-music" aria-hidden="true"></i><span>Music tags</span></h4>' +
    '<article class="mediainfo-track mediainfo-music-tags">' + content + "</article></section>";
}

function additionalDetails(media) {
  const groups = [];
  if (hasValue(media.container?.source_filename) && media.identity?.label !== "Container") {
    groups.push(["Container", [["Source filename", media.container.source_filename]]]);
  }
  media.streams.video.forEach((stream) => {
    const rows = [
      ["Profile", stream.profile],
      ["Bit depth", hasValue(stream.bit_depth) ? stream.bit_depth + "-bit" : null],
      ["HDR format", stream.hdr_format],
      ["Color primaries", stream.color_primaries],
      ["Transfer characteristics", stream.transfer_characteristics],
      ["Matrix coefficients", stream.matrix_coefficients],
    ].filter(([, value]) => hasValue(value));
    if (rows.length) groups.push(["Video " + identifier(stream), rows]);
  });
  const tags = media.music_tags || {};
  const musicRows = [
    ["MusicBrainz release ID", tags.musicbrainz_release_id],
    ["MusicBrainz recording ID", tags.musicbrainz_recording_id],
  ].filter(([, value]) => hasValue(value));
  if (musicRows.length) groups.push(["Music tags", musicRows]);
  if (!groups.length) return "";
  return '<details class="mediainfo-details"><summary>Additional details</summary>' +
    groups.map(([heading, rows]) => '<div class="mediainfo-detail-group"><h5>' + escapeHtml(heading) +
      "</h5>" + facts(rows) + "</div>").join("") + "</details>";
}

export function mediainfoModal() {
  return {
    open: false,
    loading: false,
    releaseName: "",
    requestVersion: 0,
    returnFocus: null,

    _setContent(html) {
      if (this.$refs?.content) this.$refs.content.innerHTML = html;
    },

    show(releaseId, releaseName, trigger = null) {
      const version = ++this.requestVersion;
      this.returnFocus = trigger;
      this.releaseName = releaseName || "";
      this.open = true;
      this.loading = true;
      this._setContent("");
      if (typeof this.$nextTick === "function") this.$nextTick(() => this.$refs?.closeButton?.focus());

      fetch("/release/" + encodeURIComponent(releaseId) + "/mediainfo", { headers: { Accept: "application/json" } })
        .then((response) => {
          if (!response.ok) throw new Error("Unable to load media information");
          return response.json();
        })
        .then((data) => {
          if (version !== this.requestVersion) return;
          this.releaseName = data.release_name || this.releaseName;
          this._setContent(data.media ? this._buildHtml(data.media) : '<p class="mediainfo-message">No media information available</p>');
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
      this.returnFocus?.focus?.();
      this.returnFocus = null;
    },

    _buildHtml(media) {
      const container = media.container || {};
      const identity = media.identity || {};
      const title = identity.title || (identity.label === "Container" ? container.source_filename : null);
      const summary = [
        container.format,
        container.duration_display || duration(container.duration_ms),
        container.overall_bitrate_display || bitrate(container.overall_bitrate_bps),
      ].filter(hasValue);
      const streams = media.streams || { video: [], audio: [], subtitle: [] };
      let html = '<div class="mediainfo-content"><div class="mediainfo-identity"><div class="mediainfo-eyebrow">' +
        escapeHtml(identity.label || "Container") + "</div>";
      if (hasValue(title)) html += '<div class="mediainfo-title">' + escapeHtml(title) + "</div>";
      if (summary.length) html += '<div class="mediainfo-summary">' +
        summary.map((value) => "<span>" + escapeHtml(value) + "</span>").join("") + "</div>";
      html += "</div>";
      html += musicTags(media.music_tags);
      html += section("Video", "fa-video", "video", streams.video || []);
      html += section("Audio", "fa-volume-high", "audio", streams.audio || []);
      html += section("Subtitles", "fa-closed-captioning", "subtitle", streams.subtitle || []);
      html += additionalDetails({ ...media, streams });
      return html + "</div>";
    },

    init() {
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
      document.addEventListener("keydown", (event) => {
        if (event.key === "Escape" && self.open) self.close();
      });
    },
  };
}
