#!/usr/bin/env bash
set -euo pipefail

fixture_tools=/opt/recovery-fixture-tools
mkdir -p "$fixture_tools"
apt-get update -qq
DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends python3-venv par2=0.8.1-3build1
case "$(dpkg --print-architecture)" in
  amd64) fixture_arch=amd64; fixture_hash=d1216ab8ed11f768c7fa9719ea02417dc7bb1de8467cf218107a08e7301506d8 ;;
  arm64) fixture_arch=arm64; fixture_hash=8ec247431f5b3ba9a406ed75af16e0406b8151d1cf9c579eef41c3b21e48eef1 ;;
  *) echo 'Unsupported local downloader test architecture' >&2; exit 1 ;;
esac
curl -fsSL --retry 2 "https://github.com/nzbgetcom/nzbget/releases/download/v25.4/nzbget-25.4-${fixture_arch}.deb" -o "$fixture_tools/nzbget.deb"
printf '%s  %s\n' "$fixture_hash" "$fixture_tools/nzbget.deb" | sha256sum --check -
dpkg-deb -x "$fixture_tools/nzbget.deb" "$fixture_tools/nzbget"
curl -fsSL --retry 2 https://codeload.github.com/sabnzbd/sabnzbd/tar.gz/10609644c8b5eef462845ec6443f24dd8fcba96a -o "$fixture_tools/sabnzbd.tar.gz"
printf '%s  %s\n' c4316de8adfeb1bc68aca6d5d422d10e47a953bb3f181da1f1d80e3073ec7dcb "$fixture_tools/sabnzbd.tar.gz" | sha256sum --check -
tar -xzf "$fixture_tools/sabnzbd.tar.gz" -C "$fixture_tools"
python3 -m venv "$fixture_tools/venv"
"$fixture_tools/venv/bin/pip" install -r "$fixture_tools/sabnzbd-10609644c8b5eef462845ec6443f24dd8fcba96a/requirements.txt"
"$fixture_tools/venv/bin/python" "$fixture_tools/sabnzbd-10609644c8b5eef462845ec6443f24dd8fcba96a/SABnzbd.py" --version
"$fixture_tools/nzbget/usr/bin/nzbget" --version
par2 -V
