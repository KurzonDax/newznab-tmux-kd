---
paths:
  - 'resources/**'
  - 'app/Http/Controllers/Admin/AdminContentController.php'
  - 'vite.config.js'
---

# Resources

The [root authorization policy](../../AGENTS.md#scope-and-authorization) applies, including command-specific Artisan approval.

## Alpine CSP iframe players
The Alpine CSP evaluator rejects expressions attached to iframe elements, including :src. Create iframe players from the component JavaScript and remove them on close or teardown. Give players tabindex=0 so the shared modal focus loop includes them; verify opening, keyboard focus, and cleanup in a browser.

## Frontend ownership and integration

Application pages are Blade plus the Alpine CSP build in `resources/js/alpine/`.
Core components load eagerly; page-specific components must register in
`resources/js/alpine/lazy-loader.js` and use the matching `x-data` name, otherwise
their handlers never load. `contentToggle` is an existing example.

The active forum is `resources/forum/blade-tailwind/`, including its Vue code, with
view namespace `forum::`. Edit that preset and preserve its Vite entrypoints;
do not recreate a parallel `resources/views/forum/` tree or duplicate namespaced
forum components under application components. Retained `livewire-tailwind`
templates are inactive; Laravel Pulse's views use Livewire.

`resources/css/app.css` imports the CSP stylesheet; `vite.config.js` owns the
application/forum entrypoints. The shared verifier runs the relevant build and
frontend checks; a separate successful build does not populate its reuse record.

## Design system

- Accent actions, links, focus rings, and selected states use `primary-*` tokens
  so `resources/css/app.css` can replace the accent (coral is the only one).
  Filled accent controls use the `--accent-surface` / `--accent-on` pair, not
  `primary-*` with white text. Literal green/red/yellow/cyan remain
  appropriate for success/danger/warning/info status.
- Containers use semantic surfaces such as `.card`, `.surface-panel`,
  `.surface-panel-alt`, `.auth-card`, or the `--surface-*` variables. Give color
  utilities a `dark:` treatment unless their ancestor/token handles it.
- Reuse `x-button` / `x-button-link`, the input/select/textarea/label components,
  and existing panel/navigation/display primitives. Their definitions own props
  and sizes. Escape Alpine bindings on Blade component tags (`::disabled`).
  The forum uses its own `x-forum::button` family. Compact release-row actions can
  use `release-action*`; dropdown togglers, close buttons, conditional chips,
  pagination, and attached input addons can keep their bespoke markup.
- Font Awesome is the icon system; avoid introducing another icon library.
- Use utilities or classes in `csp-safe.css` instead of inline view styles.
  Dynamic progress widths use `progress-bar` and `data-width`, initialized by
  `resources/js/progress-bar.js`. Existing database-driven forum category colors
  are an exception. Mail and standalone error views have separate styling needs.
- `app.css` has one `!important` allowance for `[x-cloak]`; its unlayered rules
  already outrank Tailwind utilities. Keep new overrides out of that allowance.

`scripts/check-design-system.sh` enforces the mechanical subset on relevant
changes; its exclusions do not prove complete visual or CSP correctness.

## Admin content interactions

Content ordering is scoped by `contenttype`. The list renders a draggable table
per group and uses `contentToggle`; grouped ordering, enabled toggles, and delete
confirmations belong in `resources/js/alpine/components/content-toggle.js`.
`AdminContentController::reorder()` accepts the exact ID set of one group and
rejects partial or mixed-type requests. New items receive the next bottom ordinal
server-side; the add form intentionally hides it. Deletion leaves ordinal gaps.
