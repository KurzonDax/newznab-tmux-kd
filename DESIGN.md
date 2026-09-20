---
name: NNTmux
description: A Usenet indexer's web frontend — browse, search and evaluate releases, and operate the indexer behind them.
colors:
  primary-50: "#eff6ff"
  primary-100: "#dbeafe"
  primary-200: "#bfdbfe"
  primary-300: "#93c5fd"
  primary-400: "#60a5fa"
  primary-500: "#3b82f6"
  primary-600: "#2563eb"
  primary-700: "#1d4ed8"
  primary-800: "#1e40af"
  primary-900: "#1e3a8a"
  primary-950: "#172554"
  surface-body: "#f8fafc"
  surface-body-dark: "#0f172a"
  surface-card: "#ffffff"
  surface-card-dark: "#1e293b"
  surface-panel-alt: "#f1f5f9"
  surface-panel-alt-dark: "#1e293b"
  surface-chrome: "#1e293b"
  surface-chrome-dark: "#020617"
  surface-hover: "#334155"
  surface-hover-dark: "#1e293b"
  surface-release-row: "#eef2f7"
  surface-release-row-dark: "#0b1220"
  border-default: "#e2e8f0"
  border-default-dark: "#334155"
  border-release-row: "#cbd5e1"
  border-release-row-dark: "#475569"
  text-muted: "#64748b"
  text-muted-dark: "#94a3b8"
typography:
  display:
    fontFamily: "Figtree, ui-sans-serif, system-ui, sans-serif"
    fontSize: "1.875rem"
    fontWeight: 700
    lineHeight: 1.2
  headline:
    fontFamily: "Figtree, ui-sans-serif, system-ui, sans-serif"
    fontSize: "1.5rem"
    fontWeight: 700
    lineHeight: 1.25
  title:
    fontFamily: "Figtree, ui-sans-serif, system-ui, sans-serif"
    fontSize: "1.125rem"
    fontWeight: 600
    lineHeight: 1.4
  body:
    fontFamily: "Figtree, ui-sans-serif, system-ui, sans-serif"
    fontSize: "0.875rem"
    fontWeight: 400
    lineHeight: 1.5
  label:
    fontFamily: "Figtree, ui-sans-serif, system-ui, sans-serif"
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
    backgroundColor: "{colors.primary-600}"
    textColor: "#ffffff"
    typography: "{typography.body}"
    rounded: "{rounded.md}"
    padding: "0.5rem 1rem"
    height: "{spacing.control-height}"
  button-primary-hover:
    backgroundColor: "{colors.primary-700}"
    textColor: "#ffffff"
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
    textColor: "#374151"
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

Its defining structural fact is that the palette is **not fixed**. Three accent schemes — blue, emerald, violet — are selectable at runtime, each with its own complete eleven-step ramp, and each crosses an independent light/dark axis. Six realisations of one vocabulary. Nothing in the system may hardcode an accent value, because the accent is a user's choice, not the design's.

**Key Characteristics:**

- Runtime-swappable accent across three schemes; class-based dark mode on top
- A 14px body baseline — the app reads dense even where the controls are comfortable
- One text family, Figtree, carrying the whole hierarchy by weight and size
- Borders define surfaces; shadow is a gentle secondary lift
- Status colour is semantic and literal; the accent belongs to actions and navigation

## Colors

A cool slate foundation under a swappable accent. Slate does the structural work in every scheme; the accent supplies identity and interaction.

### Primary

The accent is a **user preference**, not a constant. `resources/css/app.css` defines three complete ramps under `[data-color-scheme="blue"]` (also `:root`), `[data-color-scheme="emerald"]` and `[data-color-scheme="violet"]`. The frontmatter above records **blue as canonical**, because it is the `:root` default; the emerald and violet ramps are carried in full in `.impeccable/design.json`.

- **Signal Blue** (`#3b82f6`, `primary-500`): the default scheme's centre. Ramp steps 600/700/800 carry interactive states.
- **Signal Emerald** (`#10b981`, `primary-500` under the emerald scheme).
- **Signal Violet** (`#8b5cf6`, `primary-500` under the violet scheme).

Reach for `primary-*` utilities. Never a literal accent hex.

### Neutral

- **Paper** (`#f8fafc` light / `#0f172a` dark): page body.
- **Card** (`#ffffff` light / `#1e293b` dark): the raised reading surface.
- **Panel Alt** (`#f1f5f9` light / `#1e293b` dark): toolbars and filter bars — a half-step of contrast beneath a card.
- **Chrome** (`#1e293b` light / `#020617` dark): sidebar, header, footer and dropdown. Chrome is dark in both modes; only its depth changes.
- **Hairline** (`#e2e8f0` light / `#334155` dark): the border that defines every surface edge.
- **Muted Text** (`#64748b` light / `#94a3b8` dark): secondary copy, metadata, timestamps.
- **Release Row** (`#eef2f7` light / `#0b1220` dark) with its own border pair: the listing row surface, tuned separately from cards because it repeats hundreds of times.

Note that emerald and violet tint their neutrals too — their `--border-default` and `--text-muted` are green and purple cast, not slate. Consume the variables; do not substitute slate utilities for them.

### Semantic status

Green, red, yellow/orange and cyan are **literal and reserved**: success, danger, warning, info. They survive an accent change unaltered, which is the point — a completion chip must not turn violet because someone picked the violet scheme.

### Named Rules

**The Borrowed Accent Rule.** The accent is on loan from the user. Any component that bakes in a blue, emerald or violet value has broken two of the three schemes. Use `primary-*`; if a value must be literal, it is a status colour and should say so.

**The Chip Is a Readout Rule.** Chips report state; they are not decoration and not navigation-by-another-name. A chip that does not tell the reader something they would otherwise have to open the release to learn does not belong on the row.

## Typography

**Text Font:** Figtree (with `ui-sans-serif, system-ui, sans-serif` and the emoji stack), a variable face declared once at weight range `300 900`, self-hosted from `resources/fonts/Figtree.ttf`.
**Icon System:** Font Awesome Free (solid, regular, brands). No second icon library.

**Character:** One humanist sans doing every job. Hierarchy comes from size and weight, never from a second family. The result is plain by design — the releases are the content, and the type stays out of their way.

### Hierarchy

- **Display** (700, `text-3xl`/1.875rem): reserved; page titles reach it only at `sm` and above.
- **Headline** (700, `text-2xl`/1.5rem): the page title in `x-page-header`, paired with an accent-coloured icon.
- **Title** (600, `text-lg`/1.125rem): card and section headings.
- **Body** (400–500, `text-sm`/0.875rem): **the baseline.** This is the app's most-used size by a wide margin; 16px body is the exception, not the rule.
- **Label** (500, `text-xs`/0.75rem): chips, badges, metadata, table headers, small controls.

Weight vocabulary in practice is `font-medium` (the workhorse), `font-semibold` (headings and emphasis) and `font-bold` (page titles). `font-extrabold` is vestigial; do not extend it.

## Layout

The shell is a persistent dark sidebar and header around a light content column, with `body.app-shell` owning the frame.

- **Breakpoints** are Tailwind v4 defaults — `sm` 40rem, `md` 48rem, `lg` 64rem, `xl` 80rem, `2xl` 96rem. The project overrides none of them.
- **Spacing** is the Tailwind default scale. `gap-2`/`gap-3`/`gap-4` carry most intra-component rhythm; page headers use `px-6 py-6`.
- **Responsive pattern**: stack to a column on small screens, and promote to a row at `sm`. `x-page-header` is the reference implementation — `flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between`.
- **Dropdown panels** size to content within a viewport guard: `width: max-content`, `min-width: 14rem`, `max-width: calc(100vw - 2rem)`.

## Elevation & Depth

**Borders lead; shadow is secondary.** Every surface is defined by a 1px `--border-default` hairline. Shadow adds a gentle lift on top of that line — it does not replace it. In dark mode the border does nearly all the work, since a shadow against `#0f172a` reads as almost nothing.

The stack, shallowest to deepest:

1. **Body** — no border, no shadow.
2. **Panel Alt** — border only. Toolbars and filter bars sit flush.
3. **Surface Panel / Card** — border plus `shadow-md`, radius `0.75rem`.
4. **Dropdown** — border plus a mid shadow, `0 12px 30px -14px rgb(2 6 23 / 50%)`.
5. **Modal** — border plus the deep shadow, `0 24px 60px -16px rgb(2 6 23 / 55%)`.

New components reach for a border first. Add shadow only when the element genuinely floats above the page — a menu, a dialog, a popover.

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

Seven variants — `primary`, `secondary`, `muted`, `success`, `danger`, `warning`, `ghost` — across five sizes — `sm`, `md`, `lg`, `icon`, `icon-sm`. Every button is bordered, including filled ones, so the shape holds on any surface.

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

Chips carry eight tones — `neutral`, `origin`, `entity`, `primary`, `success`, `warning`, `danger`, `info` — and render as `span`, `a` or `button` depending on whether they inform, navigate or act. They consume surface variables directly (`bg-(--surface-panel-alt)`), so they follow the scheme.

Badges are the pill form: five tones, three sizes, always `rounded-full`.

The domain vocabulary these express — Completion chip, Preview chip, Discard versus Hide — is defined in `CONTEXT.md`. Match those meanings; do not coin new chip semantics in a view.

### Surfaces

`.card`, `.surface-panel`, `.surface-panel-alt`, `.auth-card` and `.release-action*` are the semantic classes. Prefer them over assembling an equivalent from utilities — they already carry their dark-mode pair.

`.card` owns surface, border, radius, shadow and its colour transition — **not padding**. Interior spacing is the caller's, and there is no single convention across the app to inherit.

### Not part of this system

The forum (`resources/forum/blade-tailwind/`) is a separate preset with its own Vite entrypoint and its own `x-forum::button` family. Laravel Pulse uses Livewire and its own styling. Neither is governed by this record.

## Do's and Don'ts

**Do**

- Use `primary-*` for accent, links, focus rings and selected states.
- Give every colour utility a `dark:` counterpart unless a token or ancestor already handles it.
- Reuse `x-button`, the input/select/textarea/label set, and the semantic surface classes.
- Put styles in `csp-safe.css` or utilities — never inline `style` attributes in views; the Alpine CSP build rejects them.
- Keep status colour literal and semantic.

**Don't**

- Don't hardcode an accent hex. Three schemes break at once.
- Don't introduce a second text family or a second icon library.
- Don't assume slate neutrals. Emerald and violet tint their borders and muted text.
- Don't reach for shadow where a border would do.
- Don't extend `font-extrabold`, and don't add border weights beyond 1px.

**Known gap, recorded not prescribed**

`prefers-reduced-motion` appears nowhere in `resources/` as of 2026-09-20, while `csp-safe.css` ships transitions and `fadeIn`/`pulse` keyframes. This record documents the system as built; closing that gap is a change to CSS and belongs to its own issue.
