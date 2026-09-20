# Native Node 24 upgrade and asset rollback (#759)

This procedure upgrades the application's **build toolchain** to Node 24.21.0 and
its bundled npm 11.19.0. It installs an official, versioned Node distribution in
the deployment account's home and selects it explicitly for builds. It does not
change `/usr/bin/node`, apt sources, shell profiles, global npm packages, or running
Node consumers. There is no version manager and no separate npm upgrade.

The operator receives a private copy with connection details and all settings
filled in. Production infrastructure details are intentionally absent from this
public document. Do not run the public template without that private configuration.
Preparing this document does not authorize executing it on production.

## Scope and evidence

The intended application revision is
`d1482d95a76cf6715ce0f1afab13999d59b1b0a1`. The live source stays at this revision;
there is **no git pull, migration, Composer install in the live checkout, or PHP
upgrade**. This is an asset rebuild of the existing application with the new Node
runtime. Its package manifests/lockfiles, frontend resources, Vite configuration,
and TypeScript transformation configuration match prerequisite #757's merged
revision. Updating to an arbitrary newer master is a separate deployment review.

[#760](https://github.com/KurzonDax/newznab-tmux-kd/pull/760) merged #757 as
`bb358efefa01d33668241aad6281e9b121cec9ea`; tested PR head was
`8491603d201d45884a5d6240725003e7af143635`. It recorded Node 24.21.0/npm 11.19.0,
a successful locked install and build, 119 passing JavaScript tests, and passing
[required CI](https://github.com/KurzonDax/newznab-tmux-kd/actions/runs/35513757401).
Local validation was Linux ARM64; CI also exercised Linux AMD64. The intervening
#758 adds Boost MCP development/canary configuration; this procedure does not
roll it out or claim to validate its deployment.

The prerequisite browser smoke loaded compiled assets and password sign-in.
**The password visibility button failed with both old and new Node versions.**
That pre-existing limitation was accepted outside #757's repair scope. Check and
record it after deployment; do not report it as passed or treat an unchanged
baseline failure alone as a reason to roll back this Node upgrade.

The exact distribution and bundled npm are listed in the
[official Node archive](https://nodejs.org/en/download/archive/v24.21.0).
Checksums below are fetched over HTTPS from the same official release directory.
This verifies download integrity; it does not add independent signature verification.

## Before beginning

Use the private copy, one Bash session as the checkout owner, and no concurrent
application deployments until smoke testing completes. Do not run the old update
scripts alongside this procedure. Needed programs: Git, Python 3, curl, tar, xz,
sha256sum, flock, native PHP 8.5 with the locked Composer platform requirements,
and Composer. The TypeScript transformer is a PHP dependency: Node alone cannot
run `npm run build`. Install both npm development dependencies and optional native
packages. Do not use `npm update`, `npm audit fix`, `--omit=dev`, or change lockfiles.

No maintenance window or service restart is needed for this **same-code** rebuild:
new hashed files are installed before atomically replacing the Vite manifest;
old hashed files remain available to in-flight pages and for rollback. This does
not apply to a source-code deployment. Processing and independent scrapers continue.
Budget time for dependency downloads and the build before promotion. Any failing
command is a stop condition. Do not skip checks to continue.

### 1. Open the deployment shell and preserve recovery inputs

The private operator copy supplies this configuration block:

```bash
: "${UPGRADE_REPO:?Set from the private operator copy}"
: "${UPGRADE_ROOT:?Set from the private operator copy}"
: "${UPGRADE_URL:?Set from the private operator copy}"
: "${UPGRADE_USER:?Set from the private operator copy}"
: "${UPGRADE_PHP:?Set from the private operator copy}"
: "${UPGRADE_COMPOSER:?Set from the private operator copy}"
```

Run the following in that same Bash session. `set -e` deliberately exits the shell
on failure. The printed recovery directory contains `session.sh`; after a dropped
connection, open Bash and run the exact reconnect command printed below. It
restores safeguards, reacquires the lock, checks the unchanged checkout and selects
the pinned runtime if extraction finished. Resume promotion only after all of
steps 2–4 completed successfully; otherwise inspect the interrupted step and finish
its checks first. A partial extraction/install is not a successful preparation.
Do not repeat the backup block over an existing operation. Keep the directory private and off the
public web root; it contains a copy of `.env` for recovery, never for the build.

```bash
set -euo pipefail
umask 077
export REPO="$UPGRADE_REPO" BASE=d1482d95a76cf6715ce0f1afab13999d59b1b0a1
export PHP="$UPGRADE_PHP" COMPOSER="$UPGRADE_COMPOSER" SITE_URL="$UPGRADE_URL"
test "$(id -un)" = "$UPGRADE_USER"
test "$(uname -m)" = x86_64
. /etc/os-release
test "$ID:$VERSION_ID" = ubuntu:24.04
cd "$REPO"
test "$(git branch --show-current)" = master
test "$(git rev-parse HEAD)" = "$BASE"
test -z "$(git status --porcelain)"
test ! -e public/hot
test ! -e storage/framework/down
test -d public/build && test ! -L public/build
test -s public/build/manifest.json
test "$(/usr/bin/node --version)" = v20.20.2
test "$(/usr/bin/npm --version)" = 10.8.2
test "$(readlink -f "$(command -v php)")" = "$(readlink -f "$PHP")"
"$PHP" -r 'exit(PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION === 5 ? 0 : 1);'
mkdir -p "$UPGRADE_ROOT"
exec 9>"$UPGRADE_ROOT/node24.lock"
flock -n 9
export RUN
RUN=$(mktemp -d "$UPGRADE_ROOT/node24-759.XXXXXXXX")
export STAGE="$RUN/source" ORIGINAL_PATH="$PATH"
export NODE_HOME="$HOME/.local/opt/node-v24.21.0-linux-x64"
printf 'Recovery directory: %s\n' "$RUN"
for key in REPO BASE PHP COMPOSER SITE_URL RUN STAGE ORIGINAL_PATH NODE_HOME UPGRADE_ROOT; do
    printf 'export %s=%q\n' "$key" "${!key}"
done > "$RUN/session.sh"
cat >> "$RUN/session.sh" <<'RECOVER'
set -euo pipefail
umask 077
exec 9>"$UPGRADE_ROOT/node24.lock"
flock -n 9
cd "$REPO"
test "$(git rev-parse HEAD)" = "$BASE"
test -z "$(git status --porcelain)"
export PATH="$ORIGINAL_PATH"
if test -x "$NODE_HOME/bin/node"; then
    export PATH="$NODE_HOME/bin:$ORIGINAL_PATH"
    hash -r
    test "$(command -v node)" = "$NODE_HOME/bin/node"
    test "$(command -v npm)" = "$NODE_HOME/bin/npm"
    test "$(node --version)" = v24.21.0
    test "$(npm --version)" = 11.19.0
fi
RECOVER
printf 'Reconnect in a fresh Bash shell: source %q\n' "$RUN/session.sh"
cp -a public/build "$RUN/build.before"
cp -p .env "$RUN/env.before"
git bundle create "$RUN/source.bundle" master
git bundle verify "$RUN/source.bundle"
git status --porcelain > "$RUN/status.before"
sha256sum package.json package-lock.json composer.json composer.lock > "$RUN/locks.before"
/usr/bin/node --version > "$RUN/node.before"
/usr/bin/npm --version > "$RUN/npm.before"
curl --fail --silent --show-error "$SITE_URL/login" -o "$RUN/login.before.html"
```

Expected: clean pinned master, old Node/npm versions, a verified Git bundle and
HTTP success for login. In a browser, record the existing password sign-in and
visibility-button behavior using a synthetic password without submitting it.
If login is already broken beyond the known toggle limitation, stop and investigate.
Backups cover all inputs this procedure changes; database/NZB backups are not
substituted by the source bundle, and this procedure does not modify that data.

### 2. Install and explicitly select the pinned build runtime

```bash
cd "$RUN"
ARCHIVE=node-v24.21.0-linux-x64.tar.xz
curl --fail --location --show-error --proto '=https' --tlsv1.2 \
    "https://nodejs.org/dist/v24.21.0/$ARCHIVE" -o "$ARCHIVE"
curl --fail --location --show-error --proto '=https' --tlsv1.2 \
    https://nodejs.org/dist/v24.21.0/SHASUMS256.txt -o SHASUMS256.txt
awk -v name="$ARCHIVE" '$2 == name {print}' SHASUMS256.txt > node.sha256
test "$(wc -l < node.sha256)" -eq 1
sha256sum --check node.sha256
test ! -e "$NODE_HOME"
mkdir -p "$(dirname "$NODE_HOME")"
tar -xJf "$ARCHIVE" -C "$(dirname "$NODE_HOME")"
export PATH="$NODE_HOME/bin:$ORIGINAL_PATH"
hash -r
test "$(command -v node)" = "$NODE_HOME/bin/node"
test "$(command -v npm)" = "$NODE_HOME/bin/npm"
test "$(node --version)" = v24.21.0
test "$(npm --version)" = 11.19.0
node --version
npm --version
```

Stop on checksum/version mismatch or an already-existing destination; inspect a
previous attempt instead of overwriting it. The original system Node/npm and
CodeGraph remain untouched. A fresh login still uses system Node; for future
application builds explicitly prepend this same `NODE_HOME/bin` as above. Do not
run globally installed native Node tools under this temporary build PATH.

### 3. Build in an isolated source directory

This extracts the pinned source without changing branches or live dependencies.
It uses disposable testing configuration, not production database credentials.
Only frontend build environment settings are copied from `.env`; quoted/multiline
or interpolated values requiring manual resolution cause a stop below.

```bash
mkdir "$STAGE"
git -C "$REPO" archive "$BASE" | tar -x -C "$STAGE"
cd "$STAGE"
cp .env.testing .env
python3 - <<'PY'
import os, pathlib, re
source = pathlib.Path(os.environ['REPO'], '.env').read_text()
lines = []
for line in source.splitlines():
    if re.match(r'^(VITE_[A-Z0-9_]+|ASSET_URL)=', line):
        value = line.split('=', 1)[1]
        if '$' in value or value.count('"') % 2 or value.count("'") % 2:
            raise SystemExit('Resolve frontend env interpolation/multiline values before building')
        lines.append(line)
with open('.env', 'a') as target:
    target.write('\n' + '\n'.join(lines) + '\n')
PY
# Avoid inherited production settings and shared Laravel cache locations.
unset APP_ENV APP_CONFIG_CACHE APP_ROUTES_CACHE APP_EVENTS_CACHE APP_PACKAGES_CACHE APP_SERVICES_CACHE VIEW_COMPILED_PATH
export APP_ENV=testing DB_CONNECTION=testing DB_DATABASE=:memory:
export CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync MAIL_MAILER=array
export COMPOSER_NO_INTERACTION=1
"$PHP" "$COMPOSER" install --prefer-dist --no-progress --no-plugins --no-scripts
"$PHP" "$COMPOSER" check-platform-reqs
"$PHP" artisan package:discover --no-interaction
npm ci --include=dev --include=optional
npm run build
npm run test:js
sha256sum --check "$RUN/locks.before"
test -s public/build/manifest.json
```

Expected: locked Composer install/platform check, locked npm install, PHP
TypeScript generation followed by Vite build, and 119 JavaScript tests pass.
A large-chunk warning was also seen in #757 and is non-fatal. Composer plugins
and install scripts are disabled in the scratch copy; package discovery is run
explicitly. Both lockfiles must remain unchanged. Do not continue on engine,
platform, download, build, or test failure. All generated types, dependencies and
compiled assets remain in the scratch copy until the promotion step.

### 4. Prepare the asset validator/publisher and rehearse recovery

This helper validates every manifest entry and referenced chunk. It accepts only
regular files, refuses changed content at an existing hashed filename, retains
old assets, and publishes the manifest with one same-filesystem atomic replace.
It is used for both deployment and rollback. A failed copy leaves the old manifest
active; an interruption after manifest replacement leaves a complete new set.

```bash
cat > "$RUN/publish-assets.py" <<'PY'
import hashlib
import json
import os
from pathlib import Path
import shutil
import sys
import tempfile

source, destination = map(Path, sys.argv[1:])
source, destination = source.resolve(), destination.resolve()
manifest = source / 'manifest.json'
data = json.loads(manifest.read_text())
required = {'resources/css/app.css', 'resources/js/app.js',
            'resources/forum/blade-tailwind/css/forum.css',
            'resources/forum/blade-tailwind/js/forum.js'}
if not required <= data.keys():
    raise SystemExit('Missing application/forum manifest entrypoints')
for item in data.values():
    for ref in item.get('imports', []) + item.get('dynamicImports', []):
        if ref not in data:
            raise SystemExit('Missing imported manifest entry')
    for name in [item['file'], *item.get('css', []), *item.get('assets', [])]:
        path = source / name
        if not path.resolve().is_relative_to(source) or not path.is_file():
            raise SystemExit('Missing/unsafe manifest asset')
files = []
for path in source.rglob('*'):
    if path.is_symlink() or not (path.is_file() or path.is_dir()):
        raise SystemExit('Unsupported source asset type')
    if path.is_file() and path != manifest:
        relative = path.relative_to(source)
        target = destination / relative
        if not target.resolve().is_relative_to(destination):
            raise SystemExit('Unsafe destination asset path')
        if target.exists() and (not target.is_file() or
                hashlib.sha256(target.read_bytes()).digest() !=
                hashlib.sha256(path.read_bytes()).digest()):
            raise SystemExit('Existing asset differs: ' + str(relative))
        files.append((path, target))
if not destination.is_dir():
    raise SystemExit('Destination build directory must already exist')
for path, target in files:
    target.parent.mkdir(parents=True, exist_ok=True)
    # New assets become readable only after their entire copy has completed.
    if not target.exists():
        fd, temporary = tempfile.mkstemp(dir=target.parent, prefix='.node24-')
        os.close(fd)
        try:
            shutil.copyfile(path, temporary)
            os.chmod(temporary, 0o644)
            os.replace(temporary, target)
        finally:
            if os.path.exists(temporary):
                os.unlink(temporary)
    for parent in (target.parent, *target.parent.parents):
        if parent == destination:
            break
        parent.chmod(0o755)
fd, temporary = tempfile.mkstemp(dir=destination, prefix='.manifest-')
os.close(fd)
try:
    shutil.copyfile(manifest, temporary)
    os.chmod(temporary, 0o644)
    os.replace(temporary, destination / 'manifest.json')
finally:
    if os.path.exists(temporary):
        os.unlink(temporary)
print('Manifest published; referenced assets verified; old assets retained')
PY
cp -a "$RUN/build.before" "$RUN/rehearsal-build"
python3 "$RUN/publish-assets.py" "$STAGE/public/build" "$RUN/rehearsal-build"
cmp "$STAGE/public/build/manifest.json" "$RUN/rehearsal-build/manifest.json"
python3 "$RUN/publish-assets.py" "$RUN/build.before" "$RUN/rehearsal-build"
cmp "$RUN/build.before/manifest.json" "$RUN/rehearsal-build/manifest.json"
```

Both directions must pass against the actual saved assets before live promotion.
No production asset is changed by this rehearsal.

### 5. Promote and check the live application

This is the live asset change. Run only when the preceding checks passed and you
intend to deploy. Keep the SSH session open until browser checks finish.

```bash
cd "$REPO"
test "$(git rev-parse HEAD)" = "$BASE"
test -z "$(git status --porcelain)"
sha256sum --check "$RUN/locks.before"
cmp public/build/manifest.json "$RUN/build.before/manifest.json"
test ! -e public/hot
python3 "$RUN/publish-assets.py" "$STAGE/public/build" "$REPO/public/build"
cmp public/build/manifest.json "$STAGE/public/build/manifest.json"
curl --fail --silent --show-error "$SITE_URL/login" -o "$RUN/login.after.html"
```

Open the configured site `/login` with browser developer tools open. Disable the
browser cache, reload, and verify compiled CSS/JS/fonts return HTTP 200 with no
new console exceptions. Select password sign-in and enter a synthetic password
without submitting it. Record the visibility-button outcome against baseline:
the known failure is an acknowledged limitation, not a newly successful check.
Check the page is styled, password sign-in remains usable, and browse a normal
page using your existing authorized session. Do not share credentials or cookies.
If the site uses an asset CDN, verify its asset URLs too; local HTTP success alone
is insufficient. Stop and roll back for new missing assets, exceptions, styling
breakage, or login regressions.

Record final checks in the private recovery directory:

```bash
cd "$REPO"
test "$(git rev-parse HEAD)" = "$BASE"
test -z "$(git status --porcelain)"
sha256sum --check "$RUN/locks.before"
test "$(/usr/bin/node --version)" = "$(cat "$RUN/node.before")"
test "$(/usr/bin/npm --version)" = "$(cat "$RUN/npm.before")"
printf 'Node=%s npm=%s source=%s\n' "$(node --version)" "$(npm --version)" "$BASE" > "$RUN/result.txt"
# Append the browser outcomes and deployment time to result.txt in your editor.
export PATH="$ORIGINAL_PATH"
hash -r
```

Keep the recovery directory and both generations of hashed assets. Do not clean
old assets during this upgrade. Closing the session releases the deployment lock.
The versioned Node install remains available for explicit use on the next build.

### 6. Roll back if a post-promotion check fails

If reconnecting, source `session.sh` from the recovery directory printed in step 1
in a Bash shell. Then run this block. Reacquiring the lock below is needed only in
a new shell; in the original shell retain its existing file descriptor 9 lock.

```bash
set -euo pipefail
if ! { true >&9; } 2>/dev/null; then
    exec 9>"$UPGRADE_ROOT/node24.lock"
    flock -n 9
fi
cd "$REPO"
test "$(git rev-parse HEAD)" = "$BASE"
test -z "$(git status --porcelain)"
python3 "$RUN/publish-assets.py" "$RUN/build.before" "$REPO/public/build"
cmp public/build/manifest.json "$RUN/build.before/manifest.json"
export PATH="$ORIGINAL_PATH"
hash -r
test "$(/usr/bin/node --version)" = "$(cat "$RUN/node.before")"
test "$(/usr/bin/npm --version)" = "$(cat "$RUN/npm.before")"
curl --fail --silent --show-error "$SITE_URL/login" -o "$RUN/login.rollback.html"
sha256sum --check "$RUN/locks.before"
```

Repeat the browser smoke against the old manifest. Rollback reselects the unchanged
system Node/npm and restores the old asset manifest with all its saved files.
The downloaded Node directory can remain unused; it has no effect without explicit
PATH selection. Live code, dependencies, caches and `.env` were never changed, so
code rollback is the pinned-HEAD/clean-tree check above, not a destructive reset.
If those checks fail, a different deployment has intervened: stop, retain the
bundle/backups, and reconcile that deployment before replacing assets. Do not
reset someone else's changes or run database rollback commands.

## Rehearsal record

Validated on 2026-09-20 using the repository's isolated `scripts/agent-sail`
Ubuntu 24.04.5 runtime, PHP 8.5.10 and Composer 2.10.3. The exact intended revision
was extracted into a disposable directory. The official **ARM64** Node 24.21.0
archive passed its release checksum, was extracted separately and selected by
PATH; it reported bundled npm 11.19.0. The exact Linux x64 archive specified
for production was also downloaded and passed its official release checksum;
it was not executed in the ARM64 rehearsal. The documented Composer install (plugins
and scripts disabled), platform check, explicit package discovery, clean
`npm ci --include=dev --include=optional`, `npm run build`, and all **119**
JavaScript tests passed. All four manifest/lockfile hashes were unchanged.
Vite's only reported build warning concerned a chunk larger than 500 kB.

The publisher was exercised against the generated assets with a distinct synthetic
previous CSS entry/manifest. Promotion preserved the previous hashed file;
rollback restored the previous manifest byte-for-byte. Missing assets, changed
content at an existing filename, path traversal, and missing imported entries were
rejected without changing the active manifest. Embedded Bash/Python syntax checks
also passed. These are implementation rehearsals, not new recurring CI suites.

Limitations: this rehearsal used ARM64 rather than the operator host's AMD64,
Composer 2.10.3 rather than its installed 2.10.2, and disposable configuration.
#757 provides separate Linux AMD64 CI evidence, but not a native-host deployment
rehearsal. Production assets were not exported; rollback against their actual
contents must pass step 4 on the host before promotion. Live environment-specific
build values, filesystem permissions, HTTP/browser behavior and any CDN remain
live-only checks. Production was inspected read-only; it has not been upgraded.
The prerequisite's password-toggle failure remains explicitly acknowledged.
