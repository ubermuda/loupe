// @ts-check
import { defineConfig } from 'astro/config';
import starlight from '@astrojs/starlight';
import { fileURLToPath } from 'node:url';
import { remarkDocsLinks } from './remark-docs-links.mjs';

const docsDir = fileURLToPath(new URL('../docs', import.meta.url));

export default defineConfig({
  markdown: {
    remarkPlugins: [[remarkDocsLinks, { docsDir }]],
  },
  integrations: [
    starlight({
      title: 'Loupe',
      description: 'A document- and site-review tool for humans working with AI agents.',
      social: [
        { icon: 'github', label: 'GitHub', href: 'https://github.com/ubermuda/loupe' },
      ],
      editLink: {
        baseUrl: 'https://github.com/ubermuda/loupe/edit/main/docs/',
      },
      sidebar: [
        { label: 'Introduction', slug: 'index' },
        { label: 'Getting started', autogenerate: { directory: 'getting-started' } },
        { label: 'Using Loupe', autogenerate: { directory: 'using' } },
        { label: 'Extending Loupe', autogenerate: { directory: 'extending' } },
        { label: 'Operating', autogenerate: { directory: 'operating' } },
        { label: 'Reference', autogenerate: { directory: 'reference' } },
        { label: 'Contributing', autogenerate: { directory: 'contributing' } },
        { label: 'Troubleshooting', slug: 'troubleshooting' },
        { label: 'Known gaps', slug: 'known-gaps' },
        { label: 'Changelog', slug: 'changelog' },
        { label: 'Security policy', slug: 'security' },
      ],
    }),
  ],
});
