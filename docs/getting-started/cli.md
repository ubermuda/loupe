---
title: "Installing the CLI"
description: "Install the loupe command-line bridge with the install script or Homebrew, or download it from a GitHub release by hand."
---


The `loupe` CLI runs on macOS and Linux, on amd64 and arm64. There is no Windows
build. Each release on the
[Releases page](https://github.com/ubermuda/loupe/releases) of the Loupe
repository has an archive for each platform and a `checksums.txt` file. A CLI
release has a tag of the form `cli/vX.Y.Z`, such as `cli/v1.0.0`. The CLI has
its own version numbers, apart from the server's.

## Install

Your Loupe instance serves an install script. Run it:

```bash
curl -fsSL https://<your Loupe instance>/install.sh | sh
```

Or install the CLI with Homebrew:

```bash
brew install ubermuda/tap/loupe
```

The Connect page of a project shows both lines for your instance.

The script downloads the newest release that your instance supports. It checks
the archive against `checksums.txt`, and installs nothing when the checksum
does not match. It then puts the binary in a directory on your `PATH`. The
script stops and changes nothing when Homebrew already installed `loupe`.
[Script options](#script-options) gives the directory it picks and the options
it takes.

## Automatic updates

A running bridge can [update itself](../extending/cli-bridge.md#updates).
Automatic updates are off by default. The `autoUpdate: true` key in the rule
file turns them on.

When the script runs in a terminal, it asks `Turn on automatic updates? [y/N]`.
With no terminal, as in a run by an agent, the script asks nothing and leaves
updates off. When the rule file already holds an `autoUpdate` key, the script
keeps it and asks nothing.

To turn updates on later, run:

```bash
loupe update auto on
```

The script writes `autoUpdate: false` when it leaves updates off, and creates
the rule file when it is absent. `loupe update auto on` changes that value to
`true` on its line, and keeps the rest of the file.
[`loupe update auto`](../../cli/README.md#loupe-update-auto) describes the
command, and the rare case where it cannot change the line.

A Homebrew install updates with `brew upgrade loupe`. With no bridge running,
`loupe update` refuses to replace a binary that Homebrew installed, and prints
that command.

## Uninstall

For a Homebrew install, run `brew uninstall loupe`.

For a script install, delete the binary. The script prints its path at the end,
such as `Installed loupe 1.0.0 at /home/you/.local/bin/loupe.`

## Script options

Give options to the script after `sh -s --`:

```bash
curl -fsSL https://<your Loupe instance>/install.sh | sh -s -- --auto-update
```

| Option | Purpose |
|---|---|
| `--install-dir <dir>` | Install `loupe` into this directory. The script creates it when it is absent |
| `--auto-update` | Turn automatic updates on, and ask nothing. If that fails, the script exits with status 2 |
| `--version <x.y.z>` | Install this release, not the newest one. A leading `v` is allowed |
| `--help` | Show the options and exit |

With no `--install-dir`, the script uses the first of these that applies:

1. The directory of the `loupe` that is already on your `PATH`, with symlinks
   resolved. The script skips a `loupe` that Homebrew owns. So a second run
   replaces the binary in place.
2. `~/.local/bin`, when it is on your `PATH`.
3. `~/bin`, when it is on your `PATH`.
4. The directory you type at the prompt, in a terminal. The default is
   `~/.local/bin`.
5. `~/.local/bin`.

When the directory is not on your `PATH`, the script prints the line to add to
your shell profile.

With no `--version`, the script reads the release list from the GitHub API. It
takes the highest `cli/vX.Y.Z` release of the major version your instance
supports. It skips a pre-release tag such as `cli/v1.2.0-rc1`, and any release
that GitHub marks as a draft or a prerelease. The GitHub API rate
limit can stop this step. Then use `--version`.

`--auto-update` runs `loupe update auto on`. It turns updates on also when the
rule file holds `autoUpdate: false`. When `loupe update auto on` fails, the
binary stays installed. The script prints `Auto-update: unknown` and an error
that says automatic updates are not on. Then it exits with status 2. Run
`loupe update auto on` to try again.

| Variable | Purpose |
|---|---|
| `LOUPE_INSTALL_NO_TTY` | `1` makes the script act as if there is no terminal. It asks nothing, picks the directory without a prompt, and leaves updates off |
| `LOUPE_INSTALL_TTY` | A file that the script reads the answers from, in place of `/dev/tty` |
| `LOUPE_RULES_FILE` | The rule file that the script reads and writes. The default is `rules.yaml` in your config directory |

The config directory is `~/Library/Application Support/loupe` on macOS, and
`$XDG_CONFIG_HOME/loupe` or `~/.config/loupe` on Linux.

The script needs `curl` or `wget`, and `sha256sum` or `shasum`.

## Install by hand

You can also download a release yourself. Pick the archive for your platform.
The name is `loupe_<version>_<os>_<arch>.tar.gz`:

| Platform | Archive |
|---|---|
| macOS on Apple silicon | `loupe_<version>_darwin_arm64.tar.gz` |
| macOS on Intel | `loupe_<version>_darwin_amd64.tar.gz` |
| Linux on x86-64 | `loupe_<version>_linux_amd64.tar.gz` |
| Linux on ARM64 | `loupe_<version>_linux_arm64.tar.gz` |

Download the archive and `checksums.txt` into one directory. For example, for
version 1.0.0 on an Apple silicon Mac:

```bash
curl -LO https://github.com/ubermuda/loupe/releases/download/cli/v1.0.0/loupe_1.0.0_darwin_arm64.tar.gz
curl -LO https://github.com/ubermuda/loupe/releases/download/cli/v1.0.0/checksums.txt
```

### Check the archive

`checksums.txt` lists the SHA-256 of every archive of the release. Check the
archive you downloaded against it:

```bash
shasum -a 256 -c --ignore-missing checksums.txt
```

On Linux, `sha256sum -c --ignore-missing checksums.txt` does the same.

The command prints `OK` after the name of your archive. `--ignore-missing` skips
the archives you did not download. When the command prints `FAILED`, delete the
archive and do not install it.

### Put it on your PATH

Extract the binary and move it to a directory on your `PATH` that you can
write, such as `~/.local/bin`:

```bash
tar -xzf loupe_1.0.0_darwin_arm64.tar.gz loupe
mkdir -p ~/.local/bin
mv loupe ~/.local/bin/
```

Add `~/.local/bin` to your `PATH` if it is not there yet.

## Where the binary can update

With automatic updates on, a bridge replaces the binary in its directory. That
directory must be one you can write. A binary in a directory that belongs to
root, such as `/usr/local/bin`, cannot update. The bridge then logs
`update_blocked`, and the agents page shows "Update blocked". When `loupe` is a
symlink, the directory of the file it points to counts.

## Check the install

```bash
loupe version
```

The first line names the version and the commit, such as
`loupe 1.0.0 (0f4a2c9b1d7e3f5a6b8c9d0e1f2a3b4c5d6e7f80)`. The second line names
the Go version and the platform.

After `loupe login` and `loupe init`, run `loupe status` in the repository. It
asks Loupe for the project of the repository, through the MCP server, and
prints the project name.

Next, sign in with `loupe login` and write a rule file. After a script install,
the rule file can already hold the `autoUpdate` line. Add an `accounts` block,
your projects and a `work:` entry for each kind of work to that file. A file with a `rules:` list no
longer loads.
[`cli/README.md`](../../cli/README.md) describes both, and
[Command-line bridge](../extending/cli-bridge.md) describes what the bridge
does. When worker entries already exist, the
[setup prompt](../using/mcp.md#set-up-with-one-prompt) can add a `before`
command and a teardown entry to them.
