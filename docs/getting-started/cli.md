---
title: "Installing the CLI"
description: "Download the loupe command-line bridge from a GitHub release, check it, and put it where it can update itself."
---


The `loupe` CLI runs on macOS and Linux, on amd64 and arm64. There is no Windows
build. Each release on the
[Releases page](https://github.com/ubermuda/loupe/releases) of the Loupe
repository has an archive for each platform and a `checksums.txt` file. A CLI
release has a tag of the form `vX.Y.Z`, such as `v1.0.0`.

## Download

Pick the archive for your platform. The name is
`loupe_<version>_<os>_<arch>.tar.gz`:

| Platform | Archive |
|---|---|
| macOS on Apple silicon | `loupe_<version>_darwin_arm64.tar.gz` |
| macOS on Intel | `loupe_<version>_darwin_amd64.tar.gz` |
| Linux on x86-64 | `loupe_<version>_linux_amd64.tar.gz` |
| Linux on ARM64 | `loupe_<version>_linux_arm64.tar.gz` |

Download the archive and `checksums.txt` into one directory. For example, for
version 1.0.0 on an Apple silicon Mac:

```bash
curl -LO https://github.com/ubermuda/loupe/releases/download/v1.0.0/loupe_1.0.0_darwin_arm64.tar.gz
curl -LO https://github.com/ubermuda/loupe/releases/download/v1.0.0/checksums.txt
```

## Check the archive

`checksums.txt` lists the SHA-256 of every archive of the release. Check the
archive you downloaded against it:

```bash
shasum -a 256 -c --ignore-missing checksums.txt
```

On Linux, `sha256sum -c --ignore-missing checksums.txt` does the same.

The command prints `OK` after the name of your archive. `--ignore-missing` skips
the archives you did not download. When the command prints `FAILED`, delete the
archive and do not install it.

## Put it on your PATH

Extract the binary and move it to a directory on your `PATH` that you can
write, such as `~/.local/bin`:

```bash
tar -xzf loupe_1.0.0_darwin_arm64.tar.gz loupe
mkdir -p ~/.local/bin
mv loupe ~/.local/bin/
```

The directory must be one you can write, because a running bridge
[updates itself](../extending/cli-bridge.md#updates) by replacing the binary
there. A binary in a directory that belongs to root, such as `/usr/local/bin`,
cannot update. The bridge then logs `update_blocked`, and the agents page shows
"Update blocked". When `loupe` is a symlink, the directory of the file it points
to counts.

Add `~/.local/bin` to your `PATH` if it is not there yet.

## Check the install

```bash
loupe version
```

The first line names the version and the commit, such as
`loupe 1.0.0 (0f4a2c9b1d7e3f5a6b8c9d0e1f2a3b4c5d6e7f80)`. The second line names
the Go version and the platform.

Next, sign in with `loupe login` and write a rule file.
[`cli/README.md`](../../cli/README.md) describes both, and
[Command-line bridge](../extending/cli-bridge.md) describes what the bridge
does.
