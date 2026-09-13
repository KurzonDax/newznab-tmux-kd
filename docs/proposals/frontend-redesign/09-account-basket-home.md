# 09 · Account, basket, home, auth, errors

Decision: **option 6A**.

## Account (`/account`, aliases `/profile`, `/profileedit`)

One page, a left section list and a content column; every section edits in place with `<x-input>` / `<x-select>` / `<x-textarea>` / `<x-label>` and an `<x-button>` per card. The separate view/edit pages, their different widths, and the four competing 2FA routes go away (keep the old URLs as redirects).

| Section | Cards |
|---------|-------|
| Profile | username, email, timezone, role and member-since (read-only), avatar |
| Appearance | Theme (Light / Dark / System), Colour scheme (Blue / Emerald / Violet), default view per root (read-only summary of what the browser remembered), cover views toggle if kept |
| Security | Password (current / new / confirm), Two-factor (status chip, enable/disable, recovery codes), Passkeys (list with added/last-used, Add, Remove), Sessions (sign out others) |
| API & RSS | API key in a read-only field with **Copy** and **Regenerate**; API and download limits with titled counters (`API requests (24 h) 412 / 5,000`, `Downloads (24 h) 38 / 200`); RSS feed builder |
| Downloads | usage bar with title and counter, recent downloads |
| Privacy | export request, erasure request (existing privacy-center) |
| Invitations | existing invitations pages, restyled with components |

Every card has a heading (the current "Downloads usage" card has none), every progress bar has a label and a counter.

## Basket (`/basket`, alias `/cart/index`)

The release browser in Table view (03) over the basket's releases, no toolbar search, with a sticky footer inside the card: `**3 in basket**` · spacer · **Empty basket** (secondary) · **Download 3 NZBs** (success). The row basket button toggles membership (green when in the basket; toast "Added to basket" / "Removed from basket"); the top-bar count updates. Empty state: basket icon, "Your basket is empty.", one sentence on how to add.

## Home (`/`)

A dashboard, never a JSON 404: **Latest releases** (browser in Cards rows, 8 rows, "Browse all →"), **Watchlist** (latest release per followed title, up to 5), **Trending this week** (a strip of covers), then the admin content blocks as today. When there is no admin content the other blocks still render.

## Trending (`/trending-movies`, `/trending-tv`)

The Covers view (04) sorted by grabs in the last 7 days with a rank badge on each tile and a Watch heart. Not a separate page template.

## Auth pages

Keep `layouts.guest` and `.auth-card`, with one width (`max-w-md`), one card padding, one title size, and the passkey button as `<x-button variant="secondary">`. Remove the unused `auth/verify`, `auth/google2fa`, and the unlinked `auth/2fa` flow.

## Error pages

`errors/minimal` (inline Tailwind v1) is replaced by a page that extends `layouts.guest`: the app logo, the code and a one-line explanation, a "Back to home" button, themed by the same tokens.
