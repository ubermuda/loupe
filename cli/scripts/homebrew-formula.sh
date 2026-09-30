#!/bin/sh
# Prints the Homebrew formula of one CLI release.
# Usage: homebrew-formula.sh <version> <checksums.txt>
set -eu

version=$1
checksums=$2
base="https://github.com/ubermuda/loupe/releases/download/cli/v$version"

# platform OS_ARCH prints the url and sha256 lines of one archive.
platform() {
	archive="loupe_${version}_$1.tar.gz"
	sum=$(awk -v f="$archive" '$2 == f { print $1 }' "$checksums")
	if [ -z "$sum" ]; then
		printf 'error: %s has no entry for %s\n' "$checksums" "$archive" >&2
		exit 1
	fi
	printf '      url "%s/%s"\n      sha256 "%s"\n' "$base" "$archive" "$sum"
}

darwin_arm64=$(platform darwin_arm64)
darwin_amd64=$(platform darwin_amd64)
linux_arm64=$(platform linux_arm64)
linux_amd64=$(platform linux_amd64)

cat <<EOF
class Loupe < Formula
  desc "Close the loop between Loupe and a local coding agent"
  homepage "https://github.com/ubermuda/loupe"
  version "$version"
  license "AGPL-3.0-or-later"

  on_macos do
    on_arm do
$darwin_arm64
    end
    on_intel do
$darwin_amd64
    end
  end

  on_linux do
    on_arm do
$linux_arm64
    end
    on_intel do
$linux_amd64
    end
  end

  def install
    bin.install "loupe"
  end

  test do
    assert_match version.to_s, shell_output("#{bin}/loupe version")
  end
end
EOF
