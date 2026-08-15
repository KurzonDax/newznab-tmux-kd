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
#    (Emerald/violet color schemes only retheme token-driven classes.)
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

if [ "$fail" -ne 0 ]; then
    echo "design-system: see AGENTS.md > Frontend > Design system" >&2
    exit 1
fi

exit 0
