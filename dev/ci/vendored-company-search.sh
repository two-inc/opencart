#!/usr/bin/env bash
# The shared company search modules are vendored byte for byte from the
# Magento plugin's staging branch (TWO-26721). Fails when a copy here differs
# from the one there, so a fix lands in the shared source and is re-vendored,
# never patched in this repo. Re-vendor: dev/ci/vendored-company-search.sh --write
set -euo pipefail
# Run from the repo root (CI runs a copy of this script from outside the tree).
cd "$(git rev-parse --show-toplevel)"
SOURCE_REPO="two-inc/magento-plugin"
SOURCE_REF="staging"
SOURCE_DIR="view/frontend/web/js/model"
TARGET_DIR="catalog/view/javascript/two"
MODULES=(company-search-panel company-capture-component company-identity sole-trader)
write=0
[ "${1:-}" = "--write" ] && write=1
drift=0
for module in "${MODULES[@]}"; do
    upstream="$(mktemp)"
    gh api -H 'Accept: application/vnd.github.raw' "repos/${SOURCE_REPO}/contents/${SOURCE_DIR}/${module}.js?ref=${SOURCE_REF}" > "$upstream"
    if [ "$write" = 1 ]; then
        cp "$upstream" "${TARGET_DIR}/${module}.js"
    fi
    theirs="$(sha256sum < "$upstream" | cut -d' ' -f1)"
    ours="$(sha256sum < "${TARGET_DIR}/${module}.js" | cut -d' ' -f1)"
    rm -f "$upstream"
    if [ "$theirs" != "$ours" ]; then
        echo "DRIFT ${module}.js: ours ${ours}, ${SOURCE_REPO}@${SOURCE_REF} ${theirs}"
        drift=1
    else
        echo "ok ${module}.js ${ours}"
    fi
done
exit "$drift"
