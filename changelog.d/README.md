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

Tag each line `Added`, `Changed`, `Removed` or `Fixed`. Write one sentence per
line, and leave the reasoning to the pull request body. One pull request that
ships six features writes six lines in one fragment.

A pull request whose whole diff is `docs/CHANGELOG.md` and this directory earns
no fragment. Work that never surfaces in the product or the development
workflow earns none either.

## Fold the fragments in

```sh
just changelog
```

The command reads every `<number>.md` file, puts the lines at the top of
`[Unreleased]` with the highest pull request number first, and deletes the
fragments it consumed. Run it on `main` after a merge, beside `just cs`.

`php bin/changelog.php --check` reads the fragments and reports a malformed
one. `just lint` runs it, so the gate catches a broken fragment on the branch
that wrote it.
