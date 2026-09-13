# 10 · Modals

One base, `<x-modal>`, replaces the eight hand-rolled partials (`nfo-modal`, `filelist-modal`, `preview-modal`, `mediainfo-modal`, `image-modal`, `report-modal`, `confirmation-modal`, plus the dead `details/partials/image-modal`).

## Base

- Overlay `rgba(2,6,23,.45)`, z-index 40, click on the overlay closes.
- Dialog: `.card` (12 px radius, 1 px border, large shadow), width per modal, max-height 88 vh with the body scrolling.
- **Header**: kind icon · title (13 px semibold) · subtitle (release name, 12 px muted, `break-all`) · spacer · **close button top-right** (ghost, square, `fa-xmark`, `title="Close (Esc)"`). The close button is never anywhere else.
- **Body**: content.
- **Footer** (`--surface-panel-alt`): buttons; primary action rightmost. **No caption or explanatory text** in the footer.
- Esc closes; focus moves into the dialog on open and returns to the trigger on close; body scroll is locked; one modal at a time (opening another closes the first).
- Slots: `title`, `subtitle`, `icon`, default, `footer`.

## NFO (`nfo` chip, details NFO button)

- Icon `fa-file-lines` yellow. Width min(820 px, 94 vw).
- Body: the NFO text in monospace 12 px on the dark NFO pane (`#0b1220`, `#cbd5e1` text) in **both** themes, `white-space: pre`, horizontally scrollable.
- Footer: **Copy text** · **Download .nfo** · spacer · **Details** (→ details page NFO tab) · **Download NZB** (success).
- Data: existing `/nfo/{id}` endpoint / `NfoController`.

## Preview (`Preview`, `Clip`, `Listen`, `Sample` chips; details Preview badge)

- Icon by kind: image `fa-image`, video `fa-video`, audio `fa-headphones`, sample `fa-images`; colour cyan. Title "Preview image" / "Video preview" / "Audio preview" / "Sample image".
- Body: image and sample → the image in a 16:9 black frame (object-fit contain); video → the clip player (existing `preview/video/{guid}`) with controls; audio → a compact player: album art 96 px, title and artist, format line, play button, waveform/progress, time, volume (existing `preview/audio/{guid}`).
- Footer: **Full size** (image and sample only, opens the full-size copy when one exists per ADR 0012) · spacer · **Details** · **Download NZB**.

## Media info (`media info` chip, details button)

Keep the existing `mediainfo-modal` content (it is already on the base pattern); wrap it in `<x-modal>` so the chrome matches. Opened from the chip on **every** row, including covers and cards.

## File list (file count link, details Files button)

Existing file-list content in the base; a filename and size per row; no other change.

## Watch picker (08)

Width 340 px. Title `Add “Title” to My Movies` / `Edit “Title” on My Shows`. Body: "Get releases in these categories:" and one checkbox per root category. Footer: **Add** / **Save** (primary, heart icon) · **Cancel** · (edit mode) **Remove** at the right in red. At least one box required; a warning toast otherwise.

## Report

Existing report form (reason select, description with counter) in the base; the row Report button opens it; success toast.

## Confirm

The existing confirmation modal, restyled on the base; danger confirm uses `variant="danger"`.

## Where chips lead

| Chip / control | Opens |
|----------------|-------|
| NFO | NFO modal |
| Preview / Clip / Listen / Sample | Preview modal |
| Media info | Media-info modal |
| Files count | File-list modal |
| Watch (row, tile, page) | Watch picker |
| Report | Report modal |
| Details button | details page (a page, not a modal) |

The prototype routes the media-info and file-list chips to the details page tab as a shortcut; the implementation opens the modals, which is what the app does today from list rows.
