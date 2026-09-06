// @ts-check
import { defineConfig } from 'astro/config';
import starlight from '@astrojs/starlight';
import { fileURLToPath } from 'node:url';
import { loadEnv } from 'vite';
import { remarkDocsLinks } from './remark-docs-links.mjs';

const docsDir = fileURLToPath(new URL('../docs', import.meta.url));

// The site is served from a project page, so every route sits under /loupe.
// remarkDocsLinks rewrites Markdown links to absolute routes and Astro does not
// touch those, so it has to prefix the base itself.
const base = '/loupe';

// astro.config runs in Node, where .env is not loaded for us. Absent a token the
// widget is simply not injected — see .env.example.
const env = loadEnv(process.env.NODE_ENV ?? 'development', process.cwd(), '');
const widgetHost = env.PUBLIC_SITE_REVIEW_HOST ?? 'https://loupe.dev.localhost';
// Dev server only. The token lives in a gitignored .env so a deploy would not
// have one anyway, but this makes it impossible rather than merely unlikely:
// no build output can carry the widget, whatever the environment holds.
const isDevServer = process.argv.includes('dev');
const siteReviewWidget = isDevServer && env.PUBLIC_SITE_REVIEW_TOKEN
  ? [{
      tag: 'script',
      attrs: {
        src: `${widgetHost}/site-review/widget.js`,
        'data-token': env.PUBLIC_SITE_REVIEW_TOKEN,
      },
    }]
  : [];

export default defineConfig({
  site: 'https://ubermuda.github.io',
  base,
  markdown: {
    remarkPlugins: [[remarkDocsLinks, { docsDir, base }]],
  },
  integrations: [
    starlight({
      title: 'Loupe',
      // Starlight restores each group's collapsed state from sessionStorage and
      // applies it blindly, so a group you once collapsed stays shut even when
      // the page you are on lives inside it. Re-open the ancestors of the
      // current page after that restore has run.
      head: [
        {
          tag: 'script',
          content:
            "addEventListener('DOMContentLoaded',()=>{let e=document.querySelector('#starlight__sidebar [aria-current=\"page\"]');while(e=e?.closest('details'))e.open=!0,e=e.parentElement})",
        },
        ...siteReviewWidget,
      ],
      description: 'A document- and site-review tool for humans working with AI agents.',
      social: [
        { icon: 'github', label: 'GitHub', href: 'https://github.com/ubermuda/loupe' },
      ],
      editLink: {
        baseUrl: 'https://github.com/ubermuda/loupe/edit/main/docs/',
      },
      sidebar: [
        { label: 'Introduction', slug: 'index' },
        {
          label: 'Getting started',
          collapsed: true,
          items: [
            { label: 'Choosing a path', slug: 'getting-started' },
            { slug: 'getting-started/demo' },
            { slug: 'getting-started/from-source' },
            { slug: 'getting-started/docker-compose' },
            { slug: 'getting-started/digitalocean' },
            { slug: 'getting-started/architecture' },
          ],
        },
        {
          label: 'Using Loupe',
          collapsed: true,
          items: [
            { slug: 'using/documents' },
            { slug: 'using/board' },
            { slug: 'using/mcp' },
            { slug: 'using/site-review' },
            { slug: 'using/admin' },
            { slug: 'using/data-exports' },
          ],
        },
        {
          label: 'Extending Loupe',
          collapsed: true,
          items: [
            { slug: 'extending/reverse-proxy' },
            { slug: 'extending/mercure' },
            { slug: 'extending/object-storage' },
            { slug: 'extending/oauth' },
            { slug: 'extending/billing' },
            { slug: 'extending/cli-bridge' },
          ],
        },
        {
          label: 'Operating',
          collapsed: true,
          items: [
            { slug: 'operating/first-run' },
            { slug: 'operating/migrations' },
            { slug: 'operating/post-deploy-checks' },
            { slug: 'operating/failed-messages' },
            { slug: 'operating/recovering' },
            { slug: 'operating/backups' },
            { slug: 'operating/restoring' },
          ],
        },
        {
          label: 'Reference',
          collapsed: true,
          items: [{ slug: 'reference/environment' }, { slug: 'reference/commands' }],
        },
        {
          label: 'Contributing',
          collapsed: true,
          items: [
            { label: 'Overview', slug: 'contributing' },
            { slug: 'contributing/development' },
            { slug: 'contributing/architectural-priorities' },
            { slug: 'contributing/worktrees' },
          ],
        },
        { label: 'Troubleshooting', slug: 'troubleshooting' },
        { label: 'Known gaps', slug: 'known-gaps' },
        { label: 'Changelog', slug: 'changelog' },
        { label: 'Security policy', slug: 'security' },
      ],
    }),
  ],
});
