# Docs site

An [Astro Starlight](https://starlight.astro.build/) site that renders the
Markdown in [`../docs`](../docs). It is a static build with no dependency on the
application, so it runs on the host rather than in a container.

```sh
just docs           # live preview at http://localhost:4321
just docs-build     # static build into website/dist, search index included
just docs-preview   # serve the built output rather than the dev server
```

Each recipe installs `website/node_modules` first, so the first run is slow and
the rest are not. Nothing here is part of `just ci`.

## Why the content lives outside this directory

Starlight's own `docsLoader()` hardcodes its base to `src/content/docs`. Using it
would mean moving the Markdown here, and the Markdown is meant to stay at
`../docs`: browsable on GitHub, and usable by anyone — human or agent — handed a
path in a repository they have not built.

`src/content.config.ts` therefore uses Astro's `glob()` loader with an explicit
`base` instead, keeping Starlight's `docsSchema()`. That is a supported
combination and appears in Starlight's own test fixtures, but it is off the
default path, which is worth knowing before debugging why a page did not appear.

## Two consequences to remember

**Every published page needs `title` in its frontmatter**, because
`docsSchema()` requires it. A page without it fails the build rather than
rendering untitled — loud, which is what you want.

**Three files are excluded** in `src/content.config.ts`: `NEXT_STEPS.md` (the
open-work tracker), `MANUAL_TEST_PLAN.md` (an internal QA checklist naming dev
credentials) and `AUTOMATIONS.md` (internal notes, and gitignored anyway). They
carry no frontmatter, so the build would fail on them if the exclusion were
dropped — the failure is the safety net, not the exclusion.

## Link rewriting

`docs/` is read on GitHub as well as here, so its links are ordinary relative
paths to `.md` files. Those cannot survive the build unchanged: a page written
at `docs/known-gaps.md` is served from `/known-gaps/`, one level deeper than the
file, so a relative link from it resolves one segment too far.

`remark-docs-links.mjs` resolves each link against the file that wrote it and
re-emits it as a root-relative route, lowercased to match Astro's own slugs
(`SECURITY.md` → `/security/`). Links pointing outside `docs/` — to
`examples/`, `cli/`, `LICENSE` — cannot be routes at all, so they become links
into the repository on GitHub, `tree/` for a directory and `blob/` for a file,
decided by asking the filesystem rather than trusting a redirect.

**Editing that plugin needs the content cache cleared**, or you will debug a
build that is quietly serving yesterday's HTML:

```sh
rm -rf website/node_modules/.astro website/.astro
```

Astro caches rendered Markdown, and a remark plugin changing is not something
it invalidates on.

## Not wired up yet

There is no deployment. GitHub Pages needs the repository to be public, and
`site` is deliberately unset in `astro.config.mjs` — setting it wrongly breaks
canonical URLs and the sitemap, and the final URL is not known yet. Expect a
sitemap warning on every build until then.
