---
name: NNTmux
description: A Usenet indexer's web frontend — browse, search and evaluate releases, and operate the indexer behind them.
colors:
  primary-50: "#fef3f0"
  primary-100: "#fde7e2"
  primary-200: "#ffd0c6"
  primary-300: "#ffb1a0"
  primary-400: "#fd8d77"
  primary-500: "#ff5a3c"
  primary-600: "#e5462c"
  primary-700: "#c8331a"
  primary-800: "#9a2b19"
  primary-900: "#782416"
  primary-950: "#471108"
  accent-surface: "#c8331a"
  accent-surface-dark: "#ff5a3c"
  accent-on: "#ffffff"
  accent-on-dark: "#1d0a05"
  surface-body: "#f5f5f2"
  surface-body-dark: "#0f1014"
  surface-card: "#ffffff"
  surface-card-dark: "#181a21"
  surface-panel-alt: "#e4e4dd"
  surface-panel-alt-dark: "#20232c"
  surface-chrome: "#f5f5f2"
  surface-chrome-dark: "#0f1014"
  surface-hover: "#d3d3cb"
  surface-hover-dark: "#2b2f3a"
  surface-release-row: "transparent"
  surface-release-row-dark: "transparent"
  border-default: "#d3d3cb"
  border-default-dark: "#2b2f3a"
  border-release-row: "#d3d3cb"
  border-release-row-dark: "#2b2f3a"
  text-default: "#17181c"
  text-default-dark: "#f4f4f6"
  text-muted: "#5c606b"
  text-muted-dark: "#a4a8b4"
typography:
  display:
    fontFamily: "Manrope, ui-sans-serif, system-ui, sans-serif"
    fontSize: "1.875rem"
    fontWeight: 800
    lineHeight: 1.1
  headline:
    fontFamily: "Manrope, ui-sans-serif, system-ui, sans-serif"
    fontSize: "1.5rem"
    fontWeight: 700
    lineHeight: 1.25
  title:
    fontFamily: "Manrope, ui-sans-serif, system-ui, sans-serif"
    fontSize: "1.125rem"
    fontWeight: 600
    lineHeight: 1.4
  body:
    fontFamily: "Manrope, ui-sans-serif, system-ui, sans-serif"
    fontSize: "0.875rem"
    fontWeight: 400
    lineHeight: 1.5
  label:
    fontFamily: "Manrope, ui-sans-serif, system-ui, sans-serif"
    fontSize: "0.75rem"
    fontWeight: 500
    lineHeight: 1.5
rounded:
  sm: "0.25rem"
  md: "0.5rem"
  control: "8px"
  card: "0.75rem"
  full: "9999px"
spacing:
  control-height: "42px"
  control-height-sm: "30px"
  control-inline: "14px"
  control-inline-sm: "10px"
  page-gutter: "1.5rem"
components:
  button-primary:
    backgroundColor: "{colors.accent-surface}"
    textColor: "{colors.accent-on}"
    typography: "{typography.body}"
    rounded: "{rounded.md}"
    padding: "0.5rem 1rem"
    height: "{spacing.control-height}"
  button-primary-hover:
    backgroundColor: "{colors.primary-800}"
    textColor: "{colors.accent-on}"
  button-secondary:
    backgroundColor: "#ffffff"
    textColor: "#374151"
    typography: "{typography.body}"
    rounded: "{rounded.md}"
    padding: "0.5rem 1rem"
    height: "{spacing.control-height}"
  button-ghost:
    backgroundColor: "transparent"
    textColor: "#374151"
    typography: "{typography.body}"
    rounded: "{rounded.md}"
    padding: "0.5rem 1rem"
  input-control:
    backgroundColor: "{colors.surface-card}"
    textColor: "#374151"
    typography: "{typography.body}"
    rounded: "{rounded.control}"
    padding: "0 14px"
    height: "{spacing.control-height}"
  card:
    backgroundColor: "{colors.surface-card}"
    textColor: "{colors.text-default}"
    rounded: "{rounded.card}"
  chip-neutral:
    backgroundColor: "{colors.surface-panel-alt}"
    textColor: "#374151"
    typography: "{typography.label}"
    rounded: "{rounded.md}"
    padding: "0.125rem 0.5rem"
  badge-default:
    backgroundColor: "{colors.primary-100}"
    textColor: "{colors.primary-800}"
    typography: "{typography.label}"
    rounded: "{rounded.full}"
    padding: "0.125rem 0.625rem"
---

# Design System: NNTmux

## Overview

**Creative North Star: "The Well-Lit Catalog"**

> Visually appealing, easy to navigate, and easy to absorb the information being shown.
> — project maintainer, 2026-09-20

NNTmux is a catalogue you retrieve from, lit well enough that you can read it. The collection is enormous and mostly machine-generated, so the interface's job is to make a wall of releases legible: what this thing is, whether it is complete, whether it is worth taking. Chips are the instrumentation — completion, preview, origin, category — and they are read at a glance rather than studied. Around them, the surface stays quiet.

The system is built for two audiences on one chassis. The public side browses, searches and evaluates; the admin side operates the indexer that fills it. They share tokens, components and the same accent, which is why a control that grows a new variant grows it for both.

There is **one accent, coral**, and it means one thing everywhere: the primary action, or "this is on". Because it is the only accent it can never collide with a chip hue. Light and dark are a per-user choice (class-based dark mode); there is no longer a colour-scheme choice. Every value is still reached through tokens, so the whole vocabulary is replaced by editing `resources/css/app.css` — that is how this look reached every page at once (issue #776), and how the next one will.

**Key Characteristics:**

- One coral accent; light and dark themes, chosen per user
- Warm-neutral grounds; the header, footer and admin sidebar sit on the page ground behind a hairline
- A 14px body baseline on existing pages — the app reads dense even where the controls are comfortable
- One text family, Manrope, carrying the whole hierarchy by weight and size
- Borders define surfaces; shadow is a gentle secondary lift
- Status colour is semantic and literal; the accent belongs to actions, navigation and on-states

## Colors

Warm-neutral grounds under a single coral accent. The neutrals do the structural work; coral supplies identity and marks what is active.

### Primary

- **Coral** (`#ff5a3c`, `primary-500`, and `#c8331a`, `primary-700`): the two approved corals. The eleven-step `primary-*` ramp is pinned to them and derived in OKLCH at their hue, declared once in the `@theme` block of `resources/css/app.css`.

Reach for `primary-*` utilities for accent text, rings and tinted states. Never a literal accent hex.

### Filled accent controls: the pair

A filled coral control uses **`--accent-surface`** with **`--accent-on`**, never `primary-*` with white:

| Theme | Surface | Text | Contrast |
|---|---|---|---|
| Light | `#c8331a` | `#ffffff` | 5.3:1 |
| Dark | `#ff5a3c` | `#1d0a05` | 6.2:1 |

White on `#ff5a3c` is 3.1:1 and fails, which is why the dark theme's text is near-black. In utilities the pair is `bg-accent-surface text-accent-on dark:bg-accent-surface-dark dark:text-accent-on-dark`; in `csp-safe.css` it is the variables with a `.dark` twin rule. Hover goes one step deeper in light (`primary-800`) and one step lighter in dark (`primary-400`). `tests/Unit/DesignTokenContrastTest.php` computes these ratios from `app.css`.

### Neutral

Every token has a light value and a `-dark` twin.

- **Paper** (`--surface-body`, `#f5f5f2` / `#0f1014`): the page ground.
- **Card** (`--surface-card`, `--surface-dropdown`, `#ffffff` / `#181a21`): the raised reading surface and menus.
- **Panel Alt** (`--surface-panel-alt`, `#e4e4dd` / `#20232c`): toolbars and filter bars — a step of contrast beneath a card.
- **Chrome** (`--surface-header`, `--surface-footer`, `--surface-sidebar`): the same value as Paper. The bar, footer and admin sidebar sit on the page ground with a `--border-default` rule, not on a dark band.
- **Hover** (`--surface-hover`, `#d3d3cb` / `#2b2f3a`): the hover fill of neutral controls and table rows.
- **Hairline** (`--border-default`, `#d3d3cb` / `#2b2f3a`): the border that defines every surface edge.
- **Text** (`--text-default`, `#17181c` / `#f4f4f6`): body text; the `body` element takes it.
- **Muted Text** (`--text-muted`, `#5c606b` / `#a4a8b4`): secondary copy, metadata, timestamps. At least 4.5:1 on the page ground and on a card, in both themes.
- **Release Row** (`--surface-release-row`, transparent, with `--border-release-row` = Hairline): rows are hairline-divided, not filled.

### Chips

`--chip-<kind>-bg` / `--chip-<kind>-fg` (with `-dark` twins) are tinted grounds with coloured text, each pair at least 4.5:1: `media`, `nfo`, `preview`, `sample`, the completion bands `completion-ok` / `-mid` / `-low`, the solid resolution chips `res-4k` / `res-1080` / `res-720` / `res-sd`, and the media-info section and value hues `mi-*`. Values come from the approved TV prototype (`docs/proposals/tv-redesign/prototype/reference/tokens.json`). They are status colours in the sense below: literal and reserved, never the accent.

### Semantic status

Green, red, yellow/orange and cyan are **literal and reserved**: success, danger, warning, info. They are never coral, which is the point — a completion chip must never be mistaken for an on-state.

### Named Rules

**The One Accent Rule.** Coral means "primary action" or "this is on", on every screen. Do not use it for decoration, and do not introduce a second accent. Reach it through `primary-*` or the accent pair; a literal value is a status colour and should say so.

**The Chip Is a Readout Rule.** Chips report state; they are not decoration and not navigation-by-another-name. A chip that does not tell the reader something they would otherwise have to open the release to learn does not belong on the row.

## Typography

**Text Font:** Manrope (with `ui-sans-serif, system-ui, sans-serif` and the emoji stack), a variable face declared once for weights `400 800`, self-hosted from `resources/fonts/Manrope.ttf` with its SIL Open Font License in `resources/fonts/OFL.txt`.
**Icon System:** Font Awesome Free (solid, regular, brands). No second icon library.

**Character:** One geometric-humanist sans doing every job. Hierarchy comes from size and weight, never from a second family. The result is plain by design — the releases are the content, and the type stays out of their way.

### Hierarchy

- **Display** (800, `text-3xl`/1.875rem): reserved; page titles reach it only at `sm` and above.
- **Headline** (700, `text-2xl`/1.5rem): the page title in `x-page-header`, paired with an accent-coloured icon.
- **Title** (600, `text-lg`/1.125rem): card and section headings.
- **Body** (400–500, `text-sm`/0.875rem): **the baseline.** This is the app's most-used size by a wide margin; 16px body is the exception, not the rule.
- **Label** (500, `text-xs`/0.75rem): chips, badges, metadata, table headers, small controls.

Weight vocabulary in practice is `font-medium` (the workhorse), `font-semibold` (controls, headings and emphasis), `font-bold` (the release name) and `font-extrabold` (800, page and show titles). The face stops at 800; nothing heavier exists.

## Layout

The public shell is a sticky header bar and a footer on the page ground around the content column. The admin shell (`body.app-shell`) adds a persistent sidebar, also on the page ground, divided from the content by hairlines.

- **Breakpoints** are Tailwind v4 defaults — `sm` 40rem, `md` 48rem, `lg` 64rem, `xl` 80rem, `2xl` 96rem. The project overrides none of them.
- **Spacing** is the Tailwind default scale. `gap-2`/`gap-3`/`gap-4` carry most intra-component rhythm; page headers use `px-6 py-6`.
- **Responsive pattern**: stack to a column on small screens, and promote to a row at `sm`. `x-page-header` is the reference implementation — `flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between`.
- **Dropdown panels** size to content within a viewport guard: `width: max-content`, `min-width: 14rem`, `max-width: calc(100vw - 2rem)`.

## Elevation & Depth

**Borders lead; shadow is secondary.** Every surface is defined by a 1px `--border-default` hairline. Shadow adds a gentle lift on top of that line — it does not replace it. In dark mode the border does nearly all the work, since a shadow against `#0f1014` reads as almost nothing.

The stack, shallowest to deepest:

1. **Body** — no border, no shadow.
2. **Panel Alt** — border only. Toolbars and filter bars sit flush.
3. **Surface Panel / Card** — border plus `shadow-md`, radius `0.75rem`.
4. **Dropdown** — border plus a mid shadow, `0 12px 30px -14px rgb(2 6 23 / 50%)`.
5. **Modal** — border plus the deep shadow, `0 24px 60px -16px rgb(2 6 23 / 55%)`.

New components reach for a border first. Add shadow only when the element genuinely floats above the page — a menu, a dialog, a popover.

`--shadow-floating` (`0 14px 30px -14px rgb(30 30 20 / 35%)`) and its dark twin (`0 18px 40px -14px rgb(0 0 0 / 70%)`) are the floating-layer shadows of the redesigned screens: there, menus, dialogs, search results and the selection bar use this shadow and **no border**. Pages not yet redesigned keep the stack above.

## Shapes

Rectangles with consistently softened corners. Nothing is circular except avatars and badges; nothing is sharp.

- **`0.25rem`** (`rounded`): the compact `.release-action-sm` listing button.
- **`0.5rem`** (`rounded-lg`): buttons, `.release-action`, most interactive controls.
- **`8px`** (`.ui-control`): the shared public control radius — deliberately near `rounded-lg`, expressed in px because the control scale is expressed in px.
- **`0.75rem`** (`--radius-card`, `rounded-xl`): cards and panels.
- **`9999px`** (`rounded-full`): badges and pill chips only.

Borders are always 1px. There is no heavier border weight in the vocabulary.

## Components

### Buttons (`x-button`, `x-button-link`)

Seven variants — `primary`, `secondary`, `muted`, `success`, `danger`, `warning`, `ghost` — across five sizes — `sm`, `md`, `lg`, `icon`, `icon-sm`. Every button is bordered, including filled ones, so the shape holds on any surface. `primary` is the accent pair.

Focus is uniform and non-negotiable: `focus:ring-2 focus:ring-primary-500 focus:ring-offset-2`, with `dark:focus:ring-offset-gray-900`. Disabled is `cursor-not-allowed` plus `opacity-60`.

The component assigns `ui-control` sizing classes itself; callers pass `size`, not padding.

### Form controls

The public scale, applied via `.public-ui`:

- **Standard** (`.ui-control`): 42px tall, 14px inline padding, 8px radius, 14px text.
- **Small** (`.ui-control-sm`): 30px tall, 10px inline padding, 12px text.
- **Icon-only** (`.ui-control-icon`): 36px square, 30px when also small.

Selects drop the native arrow and carry an inlined Font Awesome chevron. `@tailwindcss/forms` supplies the base reset. Admin keeps its own component sizes and is not on this scale.

**Two layers, and which wins depends on context.** `x-input` ships a utility baseline of its own — `rounded-lg border-gray-300 bg-white px-3 py-2 shadow-sm`, focus as `focus:border-primary-500 focus:ring-primary-500` — *and* the `ui-control` classes. Inside `.public-ui`, `.public-ui .ui-control` is the more specific selector, so the 42px/8px/14px scale wins. Outside it, the utility baseline is what renders. Do not "fix" one layer without checking which context the control actually appears in.

### Chips (`x-chip`) and badges (`x-badge`)

Chips carry eight tones — `neutral`, `origin`, `entity`, `primary`, `success`, `warning`, `danger`, `info` — and render as `span`, `a` or `button` depending on whether they inform, navigate or act. They consume surface variables directly (`bg-(--surface-panel-alt)`), so they follow the theme.

Badges are the pill form: five tones, three sizes, always `rounded-full`.

The domain vocabulary these express — Completion chip, Preview chip, Discard versus Hide — is defined in `CONTEXT.md`. Match those meanings; do not coin new chip semantics in a view.

### Surfaces

`.card`, `.surface-panel`, `.surface-panel-alt`, `.auth-card` and `.release-action*` are the semantic classes. Prefer them over assembling an equivalent from utilities — they already carry their dark-mode pair.

`.card` owns surface, border, radius, shadow and its colour transition — **not padding**. Interior spacing is the caller's, and there is no single convention across the app to inherit.

### Not part of this system

The forum (`resources/forum/blade-tailwind/`) is a separate preset with its own Vite entrypoint and its own `x-forum::button` family. It reads the shared `primary-*` ramp, so it is coral too, but its own `--font-sans` still names Figtree, which is no longer shipped; the forum falls back to the system sans until its preset is updated. Laravel Pulse uses Livewire and its own styling. Neither is governed by this record.

## Do's and Don'ts

The rules below fall into two groups that behave very differently when the design changes. Read the distinction before treating any of them as fixed.

### Enforced: the indirection, not the vocabulary

`scripts/check-design-system.sh` mechanically rejects these. It runs in the frontend verification lane (`scripts/agent-verify` gates it behind `if frontend:`, so a change touching no frontend path does not trigger it) and is a required CI command. Its guard is `tests/Unit/DesignSystemLintTest.php`.

- Use `primary-*` for accent, links, focus rings and selected states. Literal `blue-*` in views (`:27`) or assigned from JavaScript (`:87`), and `indigo-*`/`purple-*` anywhere public (`:76`), are rejected.
- Use semantic surface classes or tokens. Literal `bg-white` and `bg-gray-N` in public views are rejected (`:72`).
- Nothing selects or stores a colour scheme: `data-color-scheme`, `colorScheme`, `color_scheme` and `setScheme` in views and JavaScript are rejected (`:93`).
- In the forum, every `primary-*` utility needs an explicit `dark:` treatment (`:32`).

**These rules constrain how a value is reached, never what the value is.** Every check inspects class names, not colours. A redesign that replaces the entire palette, type scale, spacing and component language passes all of them untouched, provided it routes through the token layer. The script says so itself at line 26: "Views reach the accent through tokens so app.css can replace it."

That is the point of them. Because views say `primary-600` and `--surface-card` rather than a hex value, the whole vocabulary can be replaced by editing `resources/css/app.css` — which is exactly how the blue schemes became coral. Hardcoded values would make the same replacement a repository-wide search with no way to prove it was complete.

**Enforcement scope**: `resources/views`, `resources/forum/blade-tailwind/views`, `resources/js` and `resources/css/app.css`. Email views, error pages, unused forum presets and admin templates are excluded by the script itself. Prototype HTML under `docs/` is not scanned at all — explore freely there.

### Enforced: hygiene and platform constraints

Also mechanical, but orthogonal to visual taste. These survive a redesign because none of them is an aesthetic choice.

- Never use inline `style` attributes in views (`:49`). This is a **CSP constraint** — the Alpine CSP build rejects them — not a preference. Use utilities or `csp-safe.css`; dynamic widths use `progress-bar` with `data-width`.
- Use `<x-button>`/`<x-button-link>`; the Bootstrap `btn` shim is gone and must not return (`:37`).
- Font Awesome is the only icon library (`:42`), and FA4 names such as `fa-clock-o` are rejected (`:81`). *Whether a replacement design keeps this constraint is an open question, deliberately not settled here.*
- Fixed Vue action panels in the forum need `v-cloak` (`:54`).
- `app.css` has an `!important` budget of one — the `[x-cloak]` rule (`:60`).

### Not enforced: the vocabulary this record describes

Everything else in this document is description, not law. No check inspects it, and a redesign may overturn any of it.

- Keep status colour literal and semantic, distinct from the accent.
- Don't hardcode an accent hex; reach coral through `primary-*` or the accent pair.
- Don't fill a control with `primary-*` and white text; use `--accent-surface` / `--accent-on`.
- Don't reach for shadow where a border would do, outside the floating layers of redesigned screens.
- Don't introduce a second text family.
- Don't add border weights beyond 1px.

**Known gaps, recorded not prescribed**

Accent **text** set in `text-primary-600` (`#e5462c`) is about 3.9:1 on white, below 4.5:1; `primary-700` (`#c8331a`) passes. Many views still use `primary-600` for links and active tabs from the blue era. Moving them is a view-by-view change and belongs to its own issue.

`prefers-reduced-motion` appears nowhere in `resources/` as of 2026-09-20, while `csp-safe.css` ships transitions and `fadeIn`/`pulse` keyframes. This record documents the system as built; closing that gap is a change to CSS and belongs to its own issue.
