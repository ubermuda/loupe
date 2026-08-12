import path from 'node:path';
import { visit } from 'unist-util-visit';

// docs/ is browsable on GitHub, so its links are ordinary relative paths to
// .md files. Those do not survive the build: a page written at
// docs/known-gaps.md is served from /known-gaps/, one level deeper than the
// file, so every relative link from it would resolve one segment too far.
// Each link is therefore resolved against the file that wrote it and re-emitted
// as a root-relative route. Targets outside docs/ cannot be routes at all and
// become links to the repository instead.
const REPO_BLOB = 'https://github.com/ubermuda/loupe/blob/main/';

const routeFor = (relativePath) => {
  const withoutExtension = relativePath.replace(/\.md$/, '');
  const segments = withoutExtension.split(path.sep);
  if (segments.at(-1) === 'index') segments.pop();
  const route = segments.join('/').toLowerCase();
  return route === '' ? '/' : `/${route}/`;
};

export function remarkDocsLinks({ docsDir }) {
  return (tree, file) => {
    const from = file.history[0];
    if (!from) return;

    visit(tree, 'link', (node) => {
      const [target, hash] = splitHash(node.url);
      if (!target || /^([a-z]+:|\/|#)/i.test(target)) return;

      const absolute = path.resolve(path.dirname(from), target);
      const relative = path.relative(docsDir, absolute);

      if (relative.startsWith('..')) {
        node.url = REPO_BLOB + path.relative(path.resolve(docsDir, '..'), absolute) + hash;
        return;
      }
      if (target.endsWith('.md')) node.url = routeFor(relative) + hash;
    });
  };
}

const splitHash = (url) => {
  const index = url.indexOf('#');
  return index === -1 ? [url, ''] : [url.slice(0, index), url.slice(index)];
};
