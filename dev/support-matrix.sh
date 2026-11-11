#!/usr/bin/env bash
# dev/support-matrix.sh: discover and classify the OpenCart x PHP combinations
# CI tests, read from upstream on every run (TWO-27172).
#
# Usage:
#   dev/support-matrix.sh              human-readable report
#   dev/support-matrix.sh --emit-all   one JSON object for CI:
#     {"e2e": [runnable combos], "skips": [combos not run, with reasons],
#      "php_unit": [PHP minors for the unit and lint jobs]}
#   dev/support-matrix.sh --php-unit   the php_unit list alone, as a JSON
#     array. Reads only this checkout and php.net, so an outage of GitHub or
#     Docker Hub cannot block the unit jobs.
#
# Support window, and where each part comes from:
#   PHP          php.net/releases (?json): the minors in supported_versions,
#                that is active or security support, not end of life. Each
#                minor's first GA release date: ?json&max=-1&version=<major>.
#   OpenCart     OpenCart publishes no support policy, so every release in the
#                series the plugin declares is in the window: the
#                opencart/opencart GitHub releases (not drafts or pre-releases)
#                from the declared floor up, within its series. A newer series
#                (4.2, 5.x) gets a warning, never legs: the plugin does not
#                declare it. The releases in oc_unsupported (below) are in
#                the window but never get legs: the plugin does not support
#                them.
#                The PHP each release accepts: "require.php" in its own
#                composer.json at the release tag, the same floor its
#                installer checks. OpenCart states a floor and no ceiling.
#   Floors       The plugin's declared minimums: OPENCART_FLOOR in the
#                Makefile (the sources PHPStan checks against) and phpVersion
#                in phpstan.neon. PHP minors from the declared floor stay in
#                the window even where php.net has ended their support.
#   Downloads    Each release zip's sha256, from dev/opencart-releases.sha256
#                and from the digest GitHub publishes for the release asset.
#                When both exist and differ, discovery fails: the download
#                was swapped. A release with neither is skipped.
#
# Matrices:
#   php_unit  Every PHP minor from the declared floor to the newest supported
#             one. setup-php resolves the newest patch of each.
#   e2e       Every OpenCart release in the window, times every PHP minor in
#             php_unit, classified, first match wins:
#               skip  OpenCart X is unsupported (oc_unsupported): an upstream
#                     bug breaks orders there
#               skip  OpenCart X requires PHP Y or later (its composer.json)
#               skip  no php:<minor>-apache image to build the shop on
#               run, provisional: the PHP minor's first GA release came after
#                     the OpenCart release was tagged. OpenCart declares only a
#                     floor, so a release cannot have declared a PHP that did
#                     not exist yet. Every php.net minor gets legs from its
#                     first GA release, but these run non-blocking
#                     (continue-on-error) with a warning, so they are exercised
#                     and cannot block.
#               run   otherwise, blocking
#
# Honest CI (TWO-24998): a transport or parse failure fails discovery, because
# an empty matrix would skip green, as does a matrix with no blocking leg,
# because it could never go red. A Docker Hub lookup that errors (anything but
# a clean 200 or 404) counts as published, so a registry blip shows as a red
# leg rather than a silent skip.

set -euo pipefail
cd "$(dirname "$0")/.."

mode=report
case "${1:-}" in
    --emit-all) mode=all ;;
    --php-unit) mode=unit ;;
    "") ;;
    *) echo "usage: $0 [--emit-all | --php-unit]" >&2; exit 2 ;;
esac

REPO=opencart/opencart
HUB=https://hub.docker.com/v2/namespaces/library/repositories/php/tags
DIGESTS=dev/opencart-releases.sha256

# OpenCart releases the plugin does not support: an upstream bug there breaks
# orders for every payment method. Each gets visible skip rows and a warning,
# never a leg. Any release not listed is picked up as usual. The extension
# warns merchants on the same releases from its own list (TWO-27238): change
# the two together.
declare -A oc_unsupported=(
    [4.1.0.1]="fixed in 4.1.0.4 (TWO-27233: guest checkout fails)"
    [4.1.0.2]="fixed in 4.1.0.4 (TWO-27232: an admin order edit drops the order to status 0)"
    [4.1.0.3]="fixed in 4.1.0.4 (TWO-27232: an admin order edit drops the order to status 0)"
)

log() { if [ "$mode" = report ]; then echo "$@"; fi; }
die() { echo "::error::support-matrix: $*" >&2; exit 2; }
fetch() {
    curl -fsSL --retry 3 --retry-all-errors --retry-delay 3 --max-time 30 "$@" \
        || die "could not fetch ${*: -1}"
}
# github <path>: the GitHub REST API, with the job's token where there is one
# (the anonymous limit is 60 calls an hour per address).
github() {
    local token=${GH_TOKEN:-${GITHUB_TOKEN:-}}
    if [ -n "$token" ]; then
        fetch -H 'Accept: application/vnd.github+json' -H "Authorization: Bearer $token" "https://api.github.com/$1"
    else
        fetch -H 'Accept: application/vnd.github+json' "https://api.github.com/$1"
    fi
}
# ver_le A B: A <= B in version order.
ver_le() { [ "$(printf '%s\n%s\n' "$1" "$2" | sort -V | head -1)" = "$1" ]; }
series() { cut -d. -f1,2 <<<"$1"; }

# hub_tag <tag>: exists | missing | error, for php:<tag> on Docker Hub.
# Docker Hub's API, not the registry, so it cannot spend the anonymous pull
# allowance the legs need.
hub_tag() {
    local code=000
    for _ in 1 2; do
        code=$(curl -sS -o /dev/null -w '%{http_code}' --max-time 20 "$HUB/$1" 2>/dev/null) || code=000
        case $code in
            200) echo exists; return ;;
            404) echo missing; return ;;
        esac
        sleep 3
    done
    echo "::warning::support-matrix: Docker Hub answered HTTP $code for php:$1; treating it as published" >&2
    echo error
}

# --- Declared floors --------------------------------------------------------
oc_floor=$(sed -n 's/^OPENCART_FLOOR[[:space:]]*:=[[:space:]]*\([0-9.]*\).*/\1/p' Makefile)
php_id=$(sed -n 's/^[[:space:]]*phpVersion:[[:space:]]*\([0-9]*\).*/\1/p' phpstan.neon)
[[ $oc_floor =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]] && [[ $php_id =~ ^[0-9]{5}$ ]] \
    || die "could not read the declared floors (Makefile OPENCART_FLOOR '$oc_floor', phpstan.neon phpVersion '$php_id')"
php_floor="$((php_id / 10000)).$((php_id % 10000 / 100))"
oc_series=$(series "$oc_floor")
log "Declared floors: OpenCart $oc_floor, PHP $php_floor"

# --- PHP --------------------------------------------------------------------
php_json=$(fetch 'https://www.php.net/releases/?json') || exit 2
php_supported=$(jq -r '[.[].supported_versions[]?] | unique | .[]' <<<"$php_json" | sort -V) \
    || die "php.net releases feed did not parse"
[ -n "$php_supported" ] || die "php.net lists no supported PHP minors"
php_newest=$(tail -1 <<<"$php_supported")
# Each major's latest release names its last minor, so every minor from the
# floor to the newest supported one can be listed without a hand-kept range.
php_unit=$(jq -r '.[].version' <<<"$php_json" \
    | grep -E '^[0-9]+\.[0-9]+\.' \
    | awk -F. '{ for (i = 0; i <= $2; i++) print $1 "." i }' \
    | sort -V \
    | while read -r m; do
        if ver_le "$php_floor" "$m" && ver_le "$m" "$php_newest"; then echo "$m"; fi
    done)
[ -n "$php_unit" ] || die "no PHP minors between the floor $php_floor and $php_newest"
log "PHP supported (php.net): $(tr '\n' ' ' <<<"$php_supported")"
log "PHP unit, lint and e2e: $(tr '\n' ' ' <<<"$php_unit")"
if [ "$mode" = unit ]; then
    jq -R . <<<"$php_unit" | jq -sc '.'
    exit 0
fi

# Each minor's first GA release date (YYYY-MM-DD). The first GA is the lowest
# patch php.net lists, not always .0: PHP 8.4 shipped as 8.4.1.
declare -A php_ga=()
for major in $(cut -d. -f1 <<<"$php_unit" | sort -u); do
    archive=$(fetch "https://www.php.net/releases/?json&max=-1&version=$major") || exit 2
    while read -r minor date; do
        php_ga[$minor]=$(date -u -d "$date" +%F) || die "php.net date '$date' for PHP $minor did not parse"
    done < <(jq -r 'to_entries[] | select(.key | test("^[0-9]+\\.[0-9]+\\.[0-9]+$")) | "\(.key) \(.value.date)"' <<<"$archive" \
        | sort -V \
        | awk '{ split($1, v, "."); m = v[1] "." v[2]; if (!(m in seen)) { seen[m] = 1; print m, $2, $3, $4 } }')
done
for php in $php_unit; do
    [ -n "${php_ga[$php]:-}" ] || die "php.net lists no release of PHP $php"
done

# --- OpenCart ---------------------------------------------------------------
releases=$(github "repos/$REPO/releases?per_page=100") || exit 2
# "<tag>\t<sha256 GitHub publishes for opencart-<tag>.zip, or ->\t<asset present>"
oc_releases=$(jq -r '.[] | select((.draft | not) and (.prerelease | not))
        | select(.tag_name | test("^[0-9]+\\.[0-9]+\\.[0-9]+\\.[0-9]+$"))
        | .tag_name as $t
        | ([.assets[] | select(.name == "opencart-\($t).zip")] | first) as $a
        | [$t, (($a.digest // "-") | sub("^sha256:"; "")), (if $a then "yes" else "no" end)]
        | @tsv' <<<"$releases" | sort -V) \
    || die "the GitHub releases of $REPO did not parse"
[ -n "$oc_releases" ] || die "$REPO lists no releases"
cut -f1 <<<"$oc_releases" | grep -qxF "$oc_floor" \
    || die "OpenCart $oc_floor (the declared floor) is not among the $REPO releases"
for s in $(cut -f1 <<<"$oc_releases" | while read -r t; do series "$t"; done | sort -V -u); do
    if ! ver_le "$s" "$oc_series"; then
        newest=$(cut -f1 <<<"$oc_releases" | grep "^$s\." | tail -1)
        echo "::warning title=OpenCart series not tested::OpenCart $newest is released; the plugin declares OpenCart $oc_series only, so CI does not test $s" >&2
    fi
done
oc_window=$(cut -f1 <<<"$oc_releases" \
    | while read -r t; do if [ "$(series "$t")" = "$oc_series" ] && ver_le "$oc_floor" "$t"; then echo "$t"; fi; done)

declare -A oc_sha=() oc_php=() oc_date=() oc_skip=()
log "OpenCart window (every $oc_series release from the floor):"
for v in $oc_window; do
    if [ -n "${oc_unsupported[$v]:-}" ]; then
        oc_skip[$v]="unsupported: upstream OpenCart bug, ${oc_unsupported[$v]}"
        log "  $v (${oc_skip[$v]})"
        continue
    fi
    IFS=$'\t' read -r _ published asset < <(awk -F'\t' -v t="$v" '$1 == t' <<<"$oc_releases")
    [ "$published" != - ] || published=
    recorded=$(awk -v f="opencart-$v.zip" '$1 !~ /^#/ && $2 == f { print $1 }' "$DIGESTS")
    if [ "$asset" != yes ]; then
        oc_skip[$v]="the release has no opencart-$v.zip asset"
    elif [ -n "$recorded" ] && [ -n "$published" ] && [ "$recorded" != "$published" ]; then
        die "opencart-$v.zip: GitHub publishes sha256 $published, $DIGESTS records $recorded. The download changed; check it before trusting either"
    elif [ -n "$recorded" ]; then
        oc_sha[$v]=$recorded
    elif [ -n "$published" ]; then
        oc_sha[$v]=$published
        echo "::warning title=OpenCart digest not recorded::opencart-$v.zip is not in $DIGESTS; its legs check the download against GitHub's digest $published. Record it there" >&2
    else
        oc_skip[$v]="no sha256 for opencart-$v.zip: GitHub publishes none and $DIGESTS has none"
    fi
    composer=$(fetch "https://raw.githubusercontent.com/$REPO/$v/composer.json") || exit 2
    constraint=$(jq -r '.require.php // empty' <<<"$composer") || die "OpenCart $v composer.json did not parse"
    [[ $constraint =~ ^\>=[[:space:]]*([0-9]+\.[0-9]+) ]] \
        || die "OpenCart $v composer.json requires PHP '$constraint', not a '>=' floor this script understands"
    oc_php[$v]=${BASH_REMATCH[1]}
    tagged=$(github "repos/$REPO/commits/$v" | jq -r '.commit.committer.date // empty') || exit 2
    [ -n "$tagged" ] || die "no commit date for the OpenCart $v tag"
    oc_date[$v]=${tagged%%T*}
    log "  $v (tagged ${oc_date[$v]}, requires PHP ${oc_php[$v]}, sha256 ${oc_sha[$v]:-none})"
done

# --- Classify ---------------------------------------------------------------
declare -A image=()
for php in $php_unit; do image[$php]=$(hub_tag "$php-apache"); done

entries=()
# classify <opencart release> <php minor>
classify() {
    local oc=$1 php=$2 reason='' provisional=''
    if [ -n "${oc_skip[$oc]:-}" ]; then
        reason=${oc_skip[$oc]}
    elif ! ver_le "${oc_php[$oc]}" "$php"; then
        reason="OpenCart $oc requires PHP ${oc_php[$oc]} or later (its composer.json)"
    elif [ "${image[$php]}" = missing ]; then
        reason="no php:$php-apache image to build the shop on"
    elif [[ ${php_ga[$php]} > ${oc_date[$oc]} ]]; then
        provisional="PHP $php (first GA ${php_ga[$php]}) is newer than OpenCart $oc (tagged ${oc_date[$oc]}), which declares only a PHP floor; runs non-blocking"
    fi
    entries+=("$(jq -nc --arg opencart "$oc" --arg sha256 "${oc_sha[$oc]:-}" --arg php "$php" \
        --arg reason "$reason" --arg provisional "$provisional" \
        '{opencart: $opencart, sha256: $sha256, php: $php, reason: $reason,
          provisional: ($provisional != ""), provisional_reason: $provisional}')")
}

for oc in $oc_window; do
    for php in $php_unit; do classify "$oc" "$php"; done
done

all=$(printf '%s\n' "${entries[@]}" | jq -sc '.')
e2e=$(jq -c '[.[] | select(.reason == "") | del(.reason)]' <<<"$all")
provisional=$(jq '[.[] | select(.reason == "" and .provisional)] | length' <<<"$all")
skips=$(jq -c '[.[] | select(.reason != "") | {opencart, php, reason}]' <<<"$all")
unit=$(jq -R . <<<"$php_unit" | jq -sc '.')
runs=$(jq length <<<"$e2e")
[ "$runs" -gt "$provisional" ] \
    || die "no blocking e2e combination ($runs runnable, $provisional of them non-blocking), so e2e could never fail"

if [ "$mode" = all ]; then
    jq -nc --argjson e2e "$e2e" --argjson skips "$skips" --argjson php_unit "$unit" \
        '{e2e: $e2e, skips: $skips, php_unit: $php_unit}'
    exit 0
fi

echo
echo "E2E combinations ($runs run, of which $provisional provisional, $(jq length <<<"$skips") skip):"
jq -r '.[] | "OpenCart \(.opencart)  PHP \(.php)" as $c
    | if .reason != "" then "  SKIP  \($c): \(.reason)"
      elif .provisional then "  RUN*  \($c): \(.provisional_reason)"
      else "  RUN   \($c)" end' <<<"$all"
