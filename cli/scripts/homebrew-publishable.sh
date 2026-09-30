#!/bin/sh
# Decides if a release may replace the formula in the tap.
# Usage: homebrew-publishable.sh <version> <path of the tap's Formula/loupe.rb>
# Exit 0: publish. Exit 3: skip, with a notice or a warning. Exit 1: error.
set -eu

next=$1
formula=$2

semver() { printf '%s\n' "$1" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+$'; }

semver "$next" || {
	echo "::error::$next is not a version such as 1.2.3."
	exit 1
}
[ -f "$formula" ] || exit 0

current=$(sed -n 's/^  version "\([^"]*\)"$/\1/p' "$formula" | head -n 1)
semver "$current" || {
	echo "::error::The formula in the tap has no version that this script can read."
	exit 1
}

IFS=. read -r next_major next_minor next_patch <<EOF
$next
EOF
IFS=. read -r cur_major cur_minor cur_patch <<EOF
$current
EOF

if [ "$next_major" -gt "$cur_major" ]; then
	echo "::warning::loupe $next is a new major version, and the tap holds $current. A new major needs its own formula policy, so the formula stays at $current."
	exit 3
fi
if [ "$next_major" -lt "$cur_major" ] ||
	{ [ "$next_minor" -lt "$cur_minor" ] || { [ "$next_minor" -eq "$cur_minor" ] && [ "$next_patch" -le "$cur_patch" ]; }; }; then
	echo "::notice::The tap holds loupe $current, which is not older than $next. The formula stays unchanged."
	exit 3
fi
