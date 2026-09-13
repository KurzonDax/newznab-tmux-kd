# 02 · Shell and navigation

Decision: **top bar only (option 1B)**. The sidebar, the hand-written mobile panel, the three search boxes, the three theme switchers and the duplicated logout forms are all removed. One category list (`Category::getForMenu`, already used by `GlobalDataComposer`) drives everything that lists categories.

## Top bar

56 px, `--surface-header`, sticky. Left to right:

1. **Logo / site name** → home.
2. **Browse ▾** (button, opens the mega-menu). `fa-compass`.
3. **Trending** (link) → the Covers view of Movies sorted by grabs over the last 7 days with a rank badge on each tile; the TV equivalent is reachable from the mega-menu. `fa-fire`.
4. **Watchlist · n** (link; n = titles followed, omitted when 0). `fa-heart`.
5. spacer
6. **Search** (see 07): a `.field` 420–460 px wide containing a scope `<select>` (All, then every root the user may see) and the text input, placeholder "Search releases…  ( / )". `/` anywhere on the page focuses it. Enter submits.
7. **Basket · n** (ghost button) → basket page. `fa-shopping-basket`.
8. **Avatar** (button) opens the user menu.

Nav items are 36 px, 13 px text, 8 px radius, transparent; hover = white 12 % fill; the current section shows a 2 px `primary-400` underline (inset box-shadow), not a filled box. The Browse trigger is a `<button>` and the others are `<a>`; they must be styled identically (strip native button chrome).

## Mega-menu

Opens under Browse; a `.card` with a 4-column grid. Each cell is a root (bold link to `/browse/{root}`) followed by its sub-categories (small links to the sub-category listing). Order: Movies, TV, Audio, Console, Books, PC/Games, Adult, Other, then a last row with "All releases", "Groups" and "Poster identities". A root the user cannot see (category permission or exclusion) is not rendered. Closes on outside click, Esc, or navigation.

The **mobile drawer** is the same list rendered vertically behind a hamburger; there is no second copy of the category tree.

## User menu

Account · Watchlist · n · Basket · n · (separator) · Theme: Light / Dark / System · Scheme: Blue / Emerald / Violet · (separator) · Sign out. Theme and scheme apply immediately and persist (existing `profile/update-theme` route). This is the **only** theme control on authenticated pages; guest pages get one small pill.

## Guests

Guest pages (`layouts.guest`) keep their own minimal chrome plus the theme pill. Authenticated pages always show the top bar; a guest hitting an authenticated layout is redirected to login rather than shown a bare page.

## Flash messages and toasts

One channel: toasts, bottom-right, stacked, 13 px, `.card` with a 3 px left edge coloured by kind (success green, info primary, warning yellow), auto-dismiss after ~3 s, optional trailing action link (e.g. **Undo**, **Open**). The layout no longer prints flash banners; the toast store consumes the flash data. Every action in this spec that says "toast" means this.

## Page header

Every page uses `<x-page-header>` with `<x-breadcrumb>` above it. Breadcrumb is a path (`Browse › Movies`, `Movies › Dune: Part Two`, `Browse › All releases`); it **never repeats the page heading**. Page actions (RSS for this view, Clear filter, Only titles I follow) sit at the right of the header row as small secondary buttons.

## Z-index

Header 20 · menus 30 · modal overlay 40 · toasts 50. Nothing else sets a z-index.
