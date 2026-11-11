#!/usr/bin/env bash
# fetch-opencart.sh VERSION SHA256 DEST
#
# Downloads an upstream OpenCart release zip, checks its sha256 and unpacks
# its upload/ tree into DEST. PHPStan scans these sources so the adapter is
# checked against the real OpenCart classes at the supported floor. Skips the
# download when DEST already holds VERSION.
set -euo pipefail

version="$1"
sha256="$2"
dest="$3"

if [ "$(cat "${dest}/.opencart-version" 2>/dev/null)" = "$version" ]; then
  exit 0
fi

tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT

curl -fsSL -o "${tmp}/opencart.zip" \
  "https://github.com/opencart/opencart/releases/download/${version}/opencart-${version}.zip"
echo "${sha256}  ${tmp}/opencart.zip" | sha256sum -c --quiet -
unzip -q "${tmp}/opencart.zip" 'upload/*' -d "$tmp"

rm -rf "$dest"
mkdir -p "$(dirname "$dest")"
mv "${tmp}/upload" "$dest"
echo "$version" > "${dest}/.opencart-version"
echo "fetch-opencart: OpenCart ${version} sources in ${dest}"
