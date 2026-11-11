#!/usr/bin/env bash
# Builds dist/two.ocmod.zip from the committed tree at HEAD, so the archive
# holds exactly what was reviewed. Only the extension's own paths ship; dev/,
# tests/ and the repo tooling stay out.
#
# OpenCart's installer takes the extension code from the file name, so the
# archive is always two.ocmod.zip. The version travels inside, in install.json.
set -euo pipefail

cd "$(dirname "$0")/.."

shipped=(install.json admin catalog system)
out=dist/two.ocmod.zip

version=$(git show HEAD:install.json | php -r '$m = json_decode(stream_get_contents(STDIN), true); echo is_array($m) ? ($m["version"] ?? "") : "";')
if [ -z "$version" ]; then
  echo "package: no version in install.json at HEAD" >&2
  exit 1
fi

if [ -n "$(git status --porcelain -- "${shipped[@]}")" ]; then
  echo "package: note: uncommitted changes in shipped paths are not included" >&2
fi

mkdir -p dist
rm -f "$out"
git archive --format=zip -o "$out" HEAD -- "${shipped[@]}"

# The archive must open with install.json at its root and carry nothing else
# at the top level.
top=$(unzip -Z1 "$out" | cut -d/ -f1 | sort -u | tr '\n' ' ')
if [ "$top" != "admin catalog install.json system " ]; then
  echo "package: unexpected top level in ${out}: ${top}" >&2
  exit 1
fi

echo "package: built ${out} (version ${version}, commit $(git rev-parse --short HEAD), $(unzip -Z1 "$out" | grep -vc '/$') files)"
