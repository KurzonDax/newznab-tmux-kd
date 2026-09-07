#!/usr/bin/env bash
set -euo pipefail
repository_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
fixture="$(mktemp -d)"
trap 'rm -rf -- "$fixture"' EXIT
mkdir "$fixture/bin"
cat > "$fixture/sail" <<'FAKE'
#!/usr/bin/env bash
if [[ "$1" == logs ]]; then echo 'container diagnostics'; exit 0; fi
attempt=$(cat "$ATTEMPT_FILE")
echo "$((attempt + 1))" > "$ATTEMPT_FILE"
[[ "$*" == *'id -u'* && "$*" == *'-w "$directory"'* ]] || exit 2
(( attempt >= READY_AFTER ))
FAKE
printf '#!/usr/bin/env bash\nexit 0\n' > "$fixture/bin/sleep"
chmod +x "$fixture/sail" "$fixture/bin/sleep"
export ATTEMPT_FILE="$fixture/attempt" PATH="$fixture/bin:$PATH"
cd "$fixture"
echo 0 > "$ATTEMPT_FILE"
READY_AFTER=2 "$repository_root/scripts/ci-sail-ready" > "$fixture/output"
[[ "$(cat "$ATTEMPT_FILE")" == 3 ]]
echo 0 > "$ATTEMPT_FILE"
if READY_AFTER=100 "$repository_root/scripts/ci-sail-ready" > "$fixture/output" 2>&1; then
    echo 'FAIL: unready Sail identity passed' >&2; exit 1
fi
[[ "$(cat "$ATTEMPT_FILE")" == 60 ]]
grep -q 'container diagnostics' "$fixture/output"
echo 'CI Sail readiness regressions passed.'
