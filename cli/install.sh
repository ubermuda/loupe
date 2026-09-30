#!/bin/sh
# Installs the loupe CLI from its GitHub release.
# Usage: curl -fsSL https://<your Loupe>/install.sh | sh -s -- [options]
set -eu

usage() {
	cat <<'EOF'
Install the loupe CLI.

Options:
  --install-dir DIR  Install loupe into DIR.
  --auto-update      Turn on automatic updates.
  --version X.Y.Z    Install this release, not the newest one.
  --help             Show this help.

Environment:
  LOUPE_INSTALL_NO_TTY=1  Treat the session as non-interactive.
EOF
}

say() { printf '%s\n' "$*"; }
die() {
	printf 'error: %s\n' "$*" >&2
	exit 1
}

has() { command -v "$1" >/dev/null 2>&1; }

# fetch URL FILE
fetch() {
	if has curl; then
		curl -fsSL -o "$2" "$1"
	else
		wget -qO "$2" "$1"
	fi
}

sha256() {
	if has sha256sum; then
		sha256sum "$1" | awk '{ print $1 }'
	else
		shasum -a 256 "$1" | awk '{ print $1 }'
	fi
}

terminal() {
	[ "${LOUPE_INSTALL_NO_TTY:-}" != 1 ] && (exec 3</dev/tty) 2>/dev/null
}

on_path() {
	case ":$PATH:" in
	*":$1:"*) return 0 ;;
	esac
	return 1
}

pick_dir() {
	if on_path "$HOME/.local/bin"; then
		say "$HOME/.local/bin"
	elif on_path "$HOME/bin"; then
		say "$HOME/bin"
	elif terminal; then
		printf 'Install directory [~/.local/bin]: ' >/dev/tty
		answer=
		read -r answer </dev/tty || true
		case $answer in
		'') say "$HOME/.local/bin" ;;
		'~' | '~'/*) say "$HOME${answer#?}" ;;
		*) say "$answer" ;;
		esac
	else
		say "$HOME/.local/bin"
	fi
}

rules_file() {
	if [ -n "${LOUPE_RULES_FILE:-}" ]; then
		say "$LOUPE_RULES_FILE"
	elif [ "$os" = darwin ]; then
		say "$HOME/Library/Application Support/loupe/rules.yaml"
	else
		say "${XDG_CONFIG_HOME:-$HOME/.config}/loupe/rules.yaml"
	fi
}

latest_version() {
	# Only plain X.Y.Z tags match, so a pre-release such as cli/v1.2.0-rc1 does not.
	grep -o '"tag_name": *"cli/v[0-9]*\.[0-9]*\.[0-9]*"' "$work/releases.json" |
		sed 's/.*"cli\/v//; s/"$//' |
		grep "^$major\." |
		sort -t. -k1,1n -k2,2n -k3,3n |
		tail -n 1
}

main() {
	major=${LOUPE_CLI_MAJOR:-1}
	url=${LOUPE_URL:-}
	github_api=${LOUPE_GITHUB_API:-https://api.github.com}
	github_download=${LOUPE_GITHUB_DOWNLOAD:-https://github.com}
	dir='' auto='' version=''

	while [ $# -gt 0 ]; do
		case $1 in
		--install-dir) [ $# -ge 2 ] || die "--install-dir needs a directory."; dir=$2; shift ;;
		--install-dir=*) dir=${1#*=} ;;
		--auto-update) auto=1 ;;
		--version) [ $# -ge 2 ] || die "--version needs a version."; version=${2#v}; shift ;;
		--version=*) version=${1#*=}; version=${version#v} ;;
		-h | --help) usage; exit 0 ;;
		*) die "unknown option $1. Run with --help to see the options." ;;
		esac
		shift
	done

	case $(uname -s) in
	Darwin) os=darwin ;;
	Linux) os=linux ;;
	*) die "unsupported operating system $(uname -s)." ;;
	esac
	case $(uname -m) in
	x86_64 | amd64) arch=amd64 ;;
	arm64 | aarch64) arch=arm64 ;;
	*) die "unsupported CPU $(uname -m)." ;;
	esac

	has curl || has wget || die "this script needs curl or wget."
	has sha256sum || has shasum || die "this script needs sha256sum or shasum to check the download."

	if has brew && brew list --formula loupe >/dev/null 2>&1; then
		say "loupe is already installed with Homebrew. To upgrade it, run: brew upgrade loupe"
		exit 0
	fi

	work=$(mktemp -d)
	trap 'rm -rf "$work"' EXIT

	if [ -n "$version" ]; then
		say "$version" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+$' || die "the version must look like 1.2.3, not $version."
	else
		fetch "$github_api/repos/ubermuda/loupe/releases?per_page=100" "$work/releases.json" ||
			die "cannot read the release list from $github_api."
		version=$(latest_version)
		[ -n "$version" ] || die "no loupe CLI release $major.x found. The GitHub API rate limit can cause this. Try again later, or use --version."
	fi

	archive="loupe_${version}_${os}_${arch}.tar.gz"
	base="$github_download/ubermuda/loupe/releases/download/cli/v$version"
	say "Downloading loupe $version for $os/$arch."
	fetch "$base/$archive" "$work/$archive" || die "cannot download $base/$archive."
	fetch "$base/checksums.txt" "$work/checksums.txt" || die "cannot download $base/checksums.txt."

	expected=$(awk -v f="$archive" '$2 == f { print $1 }' "$work/checksums.txt")
	[ -n "$expected" ] || die "checksums.txt has no entry for $archive. Nothing was installed."
	actual=$(sha256 "$work/$archive")
	[ "$actual" = "$expected" ] || die "the checksum of $archive does not match checksums.txt. Nothing was installed."

	tar -xzf "$work/$archive" -C "$work" loupe

	[ -n "$dir" ] || dir=$(pick_dir)
	mkdir -p "$dir"
	dir=$(CDPATH='' cd -- "$dir" && pwd)
	bin="$dir/loupe"
	# A rename replaces a running binary in one step, and the old process keeps its copy.
	cp "$work/loupe" "$dir/.loupe.tmp.$$"
	chmod 755 "$dir/.loupe.tmp.$$"
	mv -f "$dir/.loupe.tmp.$$" "$bin"

	if [ -n "$auto" ]; then
		choice=on
	elif grep -q '^autoUpdate:' "$(rules_file)" 2>/dev/null; then
		choice="off --keep"
	elif terminal; then
		printf 'Turn on automatic updates? [y/N] ' >/dev/tty
		answer=
		read -r answer </dev/tty || true
		case $answer in
		[yY]*) choice="on --keep" ;;
		*) choice="off --keep" ;;
		esac
	else
		choice="off --keep"
	fi
	# choice holds words that must split into arguments.
	# shellcheck disable=SC2086
	if output=$("$bin" update auto $choice </dev/null 2>&1); then
		state=unknown
		case $output in
		*"Automatic updates: on"*) state=on ;;
		*"Automatic updates: off"*) state=off ;;
		esac
	else
		say "warning: loupe could not set automatic updates:" >&2
		state=unknown
	fi

	say ""
	say "Installed loupe $version at $bin."
	say "$output"
	say "Auto-update: $state"
	[ "$state" = on ] || say "To turn on automatic updates, run: loupe update auto on"
	if ! on_path "$dir"; then
		say ""
		say "$dir is not on your PATH. Add this line to your shell profile:"
		say "  export PATH=\"$dir:\$PATH\""
	fi
	say ""
	say "Next, log in with: loupe login --url ${url:-<your Loupe URL>}"
}

main "$@"
