#!/usr/bin/env bash
#
# Design-system regression checks (issue #10).
# Fails the commit when app views drift off the styling foundation
# documented in AGENTS.md > Frontend > Design system.
#
# Scope: app-owned frontend, including the live blade-tailwind forum preset.
# Excluded: email views (cannot use the app stylesheet), unused forum presets,
# and error pages (standalone).

set -u
cd "$(dirname "$0")/.."

fail=0

report() {
    echo "design-system: $1" >&2
    echo "$2" | sed 's/^/  /' >&2
    fail=1
}

VIEWS_EXCLUDE='resources/views/(emails|components/mail|vendor/mail|errors)/'
VIEW_ROOTS=(resources/views resources/forum/blade-tailwind/views)

# 1. Accents must use the primary-* theme ramp, not hardcoded blue-*.
#    (Views reach the accent through tokens so app.css can replace it.)
hits=$(grep -rnE '[":[:space:]][a-z:-]*(bg|text|border|ring|from|to|via|divide|outline|decoration|fill|stroke|accent)-blue-[0-9]' \
    "${VIEW_ROOTS[@]}" --include='*.blade.php' | grep -vE "$VIEWS_EXCLUDE" || true)
[ -n "$hits" ] && report "hardcoded blue-* accent utility (use primary-*)" "$hits"

# Theme-driven forum colors must define an explicit dark-mode treatment.
hits=$(grep -rnE '(bg|text|border|ring|from|to|via|divide|outline|decoration|fill|stroke|accent)-primary-[0-9]' \
    resources/forum/blade-tailwind/views --include='*.blade.php' | grep -v 'dark:' || true)
[ -n "$hits" ] && report "forum primary-* utility without a dark: treatment" "$hits"

# 2. The Bootstrap btn shim is gone; buttons render via x-button components.
hits=$(grep -rnE 'class="(btn|btn btn-[a-z]+)[" ]' resources/views --include='*.blade.php' \
    | grep -vE "$VIEWS_EXCLUDE" || true)
[ -n "$hits" ] && report "Bootstrap btn shim class (use <x-button>/<x-button-link>)" "$hits"

# 3. Font Awesome is the only icon library in app views/JS.
hits=$(grep -rn 'data-feather' resources/views resources/js resources/forum/blade-tailwind \
    --include='*.blade.php' --include='*.js' 2>/dev/null || true)
[ -n "$hits" ] && report "feather icon usage (use Font Awesome)" "$hits"

# 4. No inline style attributes outside the documented survivors
#    (DB-driven forum category colors; values cannot be static classes).
STYLE_SURVIVORS='resources/forum/blade-tailwind/views/(category/show|category/partials/list|thread/partials/list)\.blade\.php'
hits=$(grep -rn 'style="' "${VIEW_ROOTS[@]}" --include='*.blade.php' \
    | grep -vE "$VIEWS_EXCLUDE" | grep -vE "$STYLE_SURVIVORS" || true)
[ -n "$hits" ] && report "inline style attribute (use utilities or csp-safe.css; progress-bar + data-width for dynamic widths)" "$hits"

# Vue-controlled fixed action panels must stay hidden until Vue has mounted.
hits=$(grep -rnE '<[^>]+v-show="[^"]+"[^>]+class="[^"]*fixed bottom-' \
    resources/forum/blade-tailwind/views --include='*.blade.php' | grep -v 'v-cloak' || true)
[ -n "$hits" ] && report "fixed Vue action panel without v-cloak" "$hits"

# 5. app.css stays de-escalated: only the [x-cloak] rule may use !important.
#    Unlayered rules in app.css already beat @layer utilities under Tailwind v4.
count=$(grep -c '!important' resources/css/app.css || true)
if [ "$count" -gt 1 ]; then
    report "app.css has $count !important declarations (budget: 1, the [x-cloak] rule)" \
        "$(grep -n '!important' resources/css/app.css)"
fi

# Public redesign rules. Admin templates and standalone mail/error documents
# keep their existing contracts. Shared pagination views are presentation
# components used by both public and admin pages, like resources/views/components.
PUBLIC_EXCLUDE="$VIEWS_EXCLUDE|resources/views/(admin/|components/admin/|layouts/admin\\.blade\\.php|partials/admin-menu\\.blade\\.php)"
SURFACE_EXCLUDE="$PUBLIC_EXCLUDE|resources/views/(components/|vendor/pagination/)|resources/forum/blade-tailwind/views/components/"

hits=$(grep -rnE 'bg-(white|gray-[0-9]+)([^[:alnum:]_-]|$)' "${VIEW_ROOTS[@]}" --include='*.blade.php' \
    | grep -vE "$SURFACE_EXCLUDE" || true)
[ -n "$hits" ] && report "hardcoded surface (use semantic surface classes or tokens)" "$hits"

hits=$(grep -rnE '(bg|text|border|ring|from|to|via|divide|outline|decoration|fill|stroke|accent)-(indigo|purple)-[0-9]' \
    resources/views resources/forum/blade-tailwind/views --include='*.blade.php' \
    | grep -vE "$PUBLIC_EXCLUDE" || true)
[ -n "$hits" ] && report "indigo-*/purple-* accent utility (use primary-*)" "$hits"

hits=$(grep -rnE 'fa-([a-z-]+-o|external-link)([^[:alnum:]_-]|$)' \
    resources/views resources/js resources/forum/blade-tailwind \
    --include='*.blade.php' --include='*.js' \
    | grep -vE "$PUBLIC_EXCLUDE|resources/js/(admin/|alpine/components/admin[-/])" || true)
[ -n "$hits" ] && report "FA4 icon name (use a current Font Awesome icon)" "$hits"

hits=$(grep -rnE '(bg|text|border|ring|from|to|via|divide|outline|decoration|fill|stroke|accent)-blue-[0-9]' \
    resources/js --include='*.js' \
    | grep -vE 'resources/js/(admin/|alpine/components/admin[-/])' || true)
[ -n "$hits" ] && report "JavaScript blue-* accent utility (use primary-*)" "$hits"

# Coral is the only accent: nothing may select or store a colour scheme.
hits=$(grep -rnE 'data-color-scheme|color-scheme-preference|colorScheme|color_scheme|setScheme' \
    "${VIEW_ROOTS[@]}" resources/js --include='*.blade.php' --include='*.js' \
    | grep -vE "$VIEWS_EXCLUDE" || true)
[ -n "$hits" ] && report "colour scheme reference (coral is the only accent)" "$hits"

# 6. Reordering is drag and drop (DESIGN.md > Named Rules > The Drag to
#    Reorder Rule). Admin views and scripts are checked too. Two forms fail:
#    a label or title reading "Move … up" / "Move … down", and a button that
#    holds only an up / down arrow icon in a file that marks a reorderable
#    list (the gate is the file; the script does not parse nesting).
REORDER_ROOTS=("${VIEW_ROOTS[@]}" resources/js)
Q="[\"'\`]"
NQ="[^\"'\`]"
hits=$(grep -rniE "(aria-label|ariaLabel|title).{1,14}${Q}Move ((${NQ}|${Q} *\\+${NQ}*\\+ *${Q})* )?(up|down)${Q}" \
    "${REORDER_ROOTS[@]}" --include='*.blade.php' --include='*.js' | grep -vE "$VIEWS_EXCLUDE" || true)
icon_hits=$(grep -rlE 'data-(reorder|sortable|[a-z-]*mv|drag-zone|drag-handle|grip)([^[:alnum:]_-]|$)|draggable=' \
    "${REORDER_ROOTS[@]}" --include='*.blade.php' --include='*.js' | grep -vE "$VIEWS_EXCLUDE" \
    | while IFS= read -r file; do
        perl -0777 -ne '
            my $tag = qr/(?:[^>"\x27]|"[^"]*"|\x27[^\x27]*\x27)*>/;
            my $hidden = qr/(?:\s*<span\b[^>]*\bsr-only\b[^>]*>[^<]*<\/span>)?/;
            while (/<button\b$tag$hidden\s*<i\b[^>]*\bfa-(?:arrow|chevron|caret|angle)-(?:up|down)(?![\w-])[^>]*>\s*<\/i>$hidden\s*<\/button>/g) {
                my $line = 1 + (substr($_, 0, $-[0]) =~ tr/\n//);
                print "$ARGV:$line:arrow-only button in a reorderable list\n";
            }' "$file"
    done)
hits=$(printf '%s\n%s\n' "$hits" "$icon_hits" | grep -v '^$' || true)
[ -n "$hits" ] && report "arrow-button reorder control (reordering is drag and drop; DESIGN.md > The Drag to Reorder Rule)" "$hits"

if [ "$fail" -ne 0 ]; then
    echo "design-system: see AGENTS.md > Frontend > Design system" >&2
    exit 1
fi

exit 0
