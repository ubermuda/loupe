# Changelog fragments

This directory holds one changelog fragment per pull request. A fragment is the
text that goes into the `[Unreleased]` section of `docs/CHANGELOG.md`. Nothing
else writes that section, so two branches never edit one file and never
conflict.

## Write a fragment

Name the file after your pull request number, such as `429.md`. The number
exists as soon as you open the pull request.

Put the finished changelog lines in it, anchor included:

```markdown
- (#429) — **Added:** what changed, from the reader's side.
```

Tag each line with one of the six tags Keep a Changelog defines: `Added`,
`Changed`, `Deprecated`, `Removed`, `Fixed` or `Security`. Write one sentence
per line, and leave the reasoning to the pull request body. One pull request
that ships six features writes six lines in one fragment.

A fragment holds entries and nothing else. Every line either opens an entry, or
is indented and continues the entry above it.

A pull request whose whole diff is `docs/CHANGELOG.md` and this directory earns
no fragment. Work that never surfaces in the product or the development
workflow earns none either.

## The fragments reach a reader twice

The documentation site folds them on every deploy. `.github/workflows/docs.yml`
runs `php bin/changelog.php --keep` before Astro reads `docs/`, so the published
changelog carries every fragment that has merged. `--keep` leaves the files
alone, because that checkout is thrown away and nothing commits the result. Your
entry is published as soon as the branch lands. Nobody has to do anything.

A release folds them for good:

```sh
just changelog
```

The command reads every `<number>.md` file, puts the lines at the top of
`[Unreleased]`, and deletes the fragments it consumed. That result is committed,
and it is where a version heading replaces `[Unreleased]`.

A local `npm run build` in `website/` shows the committed file alone, because
the fold lives in the workflow rather than in `package.json`. Run
`php bin/changelog.php --keep` first to preview the real thing, then
`git checkout docs/CHANGELOG.md`.

## Order, and what it costs

The fold puts the highest pull request number first. That is merge order,
except when two pull requests merge out of number order, which places a pair
the wrong way round by a few lines. No entry is lost.

## The gate

`php bin/changelog.php --check` reads the fragments and reports a malformed
one. `just lint` runs it, so the gate catches a bad fragment on the branch that
wrote it. The check reads format only. Nothing checks that a branch carries a
fragment at all.
