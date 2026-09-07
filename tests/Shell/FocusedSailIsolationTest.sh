#!/usr/bin/env bash

set -euo pipefail

repository_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
cd "$repository_root"
before_snapshot="$(mktemp)"
after_snapshot="$(mktemp)"

cleanup() {
    rm -f -- "$before_snapshot" "$after_snapshot"
}
trap cleanup EXIT

snapshot_live_caches() {
    local output="$1"
    local path relative_path checksum

    : > "$output"
    while IFS= read -r -d '' path; do
        relative_path="${path#"$repository_root/"}"
        if [[ -f "$path" ]]; then
            checksum="$(sha256sum "$path" | cut -d' ' -f1)"
        else
            checksum='-'
        fi
        stat --printf '%n\0%F\0%a\0%u\0%g\0%s\0%y\0' "$relative_path" >> "$output"
        printf '%s\0' "$checksum" >> "$output"
    done < <(find -P "$repository_root/storage/framework/views" "$repository_root/bootstrap/cache" -print0 | sort -z)
}

snapshot_live_caches "$before_snapshot"

sail_command="$repository_root/sail"
if [[ -f "$repository_root/.git" ]]; then
    sail_command="$repository_root/scripts/agent-sail"
fi
arguments=(--compact
    tests/Feature/Settings/SettingsWorkerBoundsTest.php
    tests/Feature/Settings/SettingsHubPagesTest.php)
case "${1:-}" in
    --ci)
        methods=(
            'Tests\Feature\Settings\SettingsWorkerBoundsTest::test_every_worker_field_still_lives_on_the_page_that_owns_its_pane'
            'Tests\Feature\Settings\SettingsHubPagesTest::test_the_website_page_renders_its_cards_from_the_registry'
            'Tests\Feature\Settings\SettingsHubPagesTest::test_a_picker_card_saves_and_rejects_an_out_of_range_number'
        )
        filter='/::(?:test_every_worker_field_still_lives_on_the_page_that_owns_its_pane|test_the_website_page_renders_its_cards_from_the_registry|test_a_picker_card_saves_and_rejects_an_out_of_range_number)$/'
        arguments+=(--filter "$filter" --fail-on-empty-test-suite)
        selected="$("$sail_command" artisan test "${arguments[@]}" --list-tests)"
        expected="$(printf ' - %s\n' "${methods[@]}" | LC_ALL=C sort)"
        actual="$(printf '%s\n' "$selected" | grep '^ - ' | LC_ALL=C sort)"
        [[ "$actual" == "$expected" ]] || {
            printf 'FAIL: expected exactly the three CI isolation methods; selected:\n%s\n' "$selected" >&2
            exit 1
        }
        ;;
    '') ;;
    *) echo 'Usage: FocusedSailIsolationTest.sh [--ci]' >&2; exit 2 ;;
esac
[[ "$#" -le 1 ]] || exit 2
"$sail_command" artisan test "${arguments[@]}"

snapshot_live_caches "$after_snapshot"

if ! cmp -s "$before_snapshot" "$after_snapshot"; then
    echo 'FAIL: focused issue #19 tests changed live framework caches' >&2
    diff -u <(tr '\0' '\n' < "$before_snapshot") <(tr '\0' '\n' < "$after_snapshot") >&2 || true
    exit 1
fi

echo 'Focused Sail tests left live framework caches byte-for-byte and metadata-identical.'

if [[ "${PERMISSION_TEST_SKIP_HTTP:-0}" == 1 ]]; then
    echo 'Served HTTP verification explicitly skipped for the CI cache-isolation job.'
    exit 0
fi

[[ -n "${PERMISSION_TEST_BASE_URL:-}" && -f "${PERMISSION_TEST_ADMIN_COOKIE_FILE:-}" ]] || {
    echo 'FAIL: PERMISSION_TEST_BASE_URL and PERMISSION_TEST_ADMIN_COOKIE_FILE are required for served HTTP verification' >&2
    exit 1
}

http_status="$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' \
    --cookie "$PERMISSION_TEST_ADMIN_COOKIE_FILE" \
    "${PERMISSION_TEST_BASE_URL%/}/admin/settings/engine")"
[[ "$http_status" == 200 ]] || {
    echo "FAIL: authenticated served /admin/settings/engine returned HTTP $http_status, expected 200" >&2
    exit 1
}
echo 'Authenticated served /admin/settings/engine returned HTTP 200 after the focused tests.'
